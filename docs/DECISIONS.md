# DECISIONS.md — Smaily Connect technical decisions

The canonical log of all significant technical design decisions in the Smaily
Connect plugin, with the reasoning behind each. (Maintained through Phase 3 as
`DECISIONS_DRAFT.md`; finalized under this name 2026-06-11 — the single-file
F-numbered log was chosen over an ADR-per-file split.) Purpose: a future developer
(including a future you) can quickly understand **why** the plugin is the way it
is, not just **what** it is.

Each decision follows a short form:
- **Context** — what the situation or problem was
- **Decision** — what was chosen
- **Rationale** — why
- **Alternatives** — what was rejected and why (when relevant)
- **Relationships** — how it influences other decisions (when relevant)

At the end of Phase 3 this is refined into a final `DECISIONS.md` or split into
ADR files (`docs/adr/NNNN-decision-name.md` pattern).

---

## Cross-cutting — project-wide decisions

### CC-1: Single-source-of-truth components (DRY discipline)

**Context:** the same logic in two places drifts — when one side changes, the other
falls behind. Several drift bugs were found at the end of Phase 2 (read/write key
mismatch).

**Decision:** if the same logic or data structure is used in two or more places,
create **one shared component** and delegate to it from each call site.

**Concrete examples:**
- `SubscriberPayloadBuilder.php` — WP user → Smaily payload. Backfill and
  HookHandler delegate to it.
- `buildTabPayload` (TypeScript) — wizard and Settings import the same mapper
- `Client::PATH_*` constants — all plugin↔engine URLs from one place
- `EndpointRegistry.php` — one declarative route list, Bootstrap + tests read the
  same source
- `IngestQueue` — all rec-engine event types share one table/API/idempotency
  mechanism (variant A)

**Rationale:** all the late-Phase-2 drift bugs (syncFields read/write mismatch,
restRoot/restUrl, wp_subscription, automation_mapping) **all** came from duplicated
logic. One source of truth makes drift structurally impossible.

**Consequence:** when adding a new feature, **always ask**: "is this logic already
somewhere? Can I delegate to an existing shared component?"

---

### CC-2: Read/write symmetry rule

**Context:** half the Phase 2 bugs were "writes with key X, reads with key Y" —
subtle, invisible at the unit-test level, surfaces in staging.

**Decision:** every data write must be **tested with a round-trip** — the write
happens with key X, **a test reads back with key X**. If the key names diverge, the
test fails automatically.

**Consequence:** the integration test suite (3.0) `SettingsRoundTripTest` and in
every feature sub-PR (e.g. pinning the `endpoints[ingest_catalog]` key name in
catalog).

---

### CC-3: Sensitive credentials never in code, chat, or reports

**Context:** in Phase 3 sub-PR 3.0 the agent's report leaked the setup token
(`Fey-...`). The token was rotated immediately, but the pattern had to change.

**Decision:** the **values** of setup tokens, api keys, passwords, and crypto keys
must not end up in:
- Code (committed) — use encrypted `wp_options` storage
- Chat / report text — refer to "env variable", "encrypted in DB"
- Commit messages — same
- Log files (including debug.log) — sanitize

**Consequence:** agent discipline: before sending a report, scan for sensitive
values. Credentials are passed in a separate terminal via env variables, not in chat.

---

### CC-4: Encryption via the `Cypher` class (Smaily password + rec-engine api-key)

**Context:** the plugin stores two sensitive credentials: the Smaily password and
the rec-engine api-key. Both must be encrypted at rest.

**Decision:** both are encrypted with the same `Cypher` class, stored in
`wp_options` with the **`autoload=false`** flag (doesn't go into the `alloptions`
cache — perf, and doesn't end up in mass dumps).

**Rationale:** the same pattern for both sensitive credentials means an easier
audit ("check that everything sensitive is Cypher-encrypted and `autoload=false`").
Phase 3 3.1 security checkpoint pins the cipher-string length in the DB.

---

### CC-5: Build marker (`buildHash`) against cache ambiguity

**Context:** the late-Phase-2 staging-bugfix cycle burned several iterations on
"is it a bug or stale cache?". The plugin was reinstalled several times to confirm
that staging was even running the right code.

**Decision:** the boot payload contains `buildHash` (git commit hash, with a
`-dirty` flag if the working tree is modified). A console check
`window.smailyConnectBoot.buildHash === "abc1234"` confirms in a second whether
the right code is running.

**Rationale:** without it all staging debugging is blind. ~5 lines of code, saves
hours.

**Generalization:** every future project (Shopify app, etc.) needs a marker like
this from day one.

---

### CC-6: wp-env + Chromium in the agent's environment (sees real WP)

**Context:** in late Phase 2 the agent fixed things blindly through staging — guessed
a fix, the human tested, broke, repeat. The alignment bug took 5 attempts.

**Decision:** Docker + wp-env (WP 6.9.4 + WC 10.7 + Polylang) + Chromium **in the
agent's environment**. The agent sees real WP itself, reads debug.log, reproduces
bugs.

**Rationale:** the backfill bug (missing DB table) would **never** have been
resolved through the staging cycle — it required real WP for `SHOW TABLES` to
expose what was missing. The first wp-env run resolved it.

**Rule for new projects:** if the agent is building something that runs in
environment X, the agent needs **access to environment X from day one**, not later.
Fixing blind is slow and inaccurate.

---

### CC-7: Mock server by default, live behind env flag (rec engine)

**Context:** the rec-engine setup token is **one-time use** (spec §7.1) — CI can't
run repeatedly with the same token. Every test would burn the token.

**Decision:** `RecEngineMockServer.php` (`php -S` locally) serves a spec-shaped
`/api/setup/exchange` + `/api/v1/ingest/*` mock. By default all integration tests
use the mock. **Live tests** against the real engine run only with the
`RECENGINE_LIVE=1` env flag (local manual confirmation + a Chromium walk before ZIP).

**Rationale:** the mock tests **plugin logic** (deterministic, CI-friendly), the
live call tests **compatibility** (against the contract). Different purposes, not
competing.

**Consequence + lesson:** the 3.1.2 path bug proved that **a mock can hide** a
real-engine mismatch when the mock is built around the same wrong assumption as the
plugin. **A live test is MANDATORY before ZIP** (not just "if we get to it").

---

### CC-8: Two-repo contract sync via embedded header note

**Context:** the API contract (`RECENGINE_API_CONTRACT.md`) lives in both the
plugin repo and the engine repo. The two copies must stay byte-for-byte
synchronized — and drift between them caused integration bugs in 3.1.2 (path
prefix) and 3.2.2 (endpoints-map keys). A separate `CONTRACT_SYNC.md` document
would introduce indirection (someone reads the contract, doesn't know it's
shared, doesn't think to look for a separate sync doc).

**Decision:** the sync process lives **in the contract document itself** — a
~10-line header note right after the title, documenting that the file exists
in two repos and the process for keeping them aligned.

**Rationale:**
- Information is where the reader is already looking (in the contract file).
  No need to know that a separate sync doc exists.
- New contributors on either side see the sync process the first time they
  open the contract — no tribal-knowledge erosion.
- Past drifts (`/api` prefix, `event_id` body coverage) are referenced in the
  note as concrete proof that the process matters.

**Alternatives rejected:**
- Separate `CONTRACT_SYNC.md` document — adds indirection without adding
  content. Reader has to know it exists.
- No formal documentation, "just talk in Slack" — relies on tribal knowledge,
  which the past drifts proved insufficient.
- Git submodule or a separate `smaily-recengine-contracts` repo — over-
  engineering for two consumers. Revisit when a third consumer (Shopify app,
  Milestone 3 on the plugin roadmap) arrives.

**Consequence:** the header note is implemented in both repos in the same work
session — first practical demonstration of the sync process. CI check that
verifies byte-identical content between repos is a backlog item (low priority,
triggered when a third drift occurs).

---

### CC-9: Single canonical path, not "canonical + legacy"

**Context:** when the engine implements a new contract behavior (e.g. W1
per-item Layer-2 dedup in Route A), the temptation is to retain the previous
behavior as "legacy backward-compat" alongside the new canonical path. This
creates two parallel paths in code, schema, and documentation. During W1
implementation, the engine team initially kept wrapper-level `event_id` dedup
as a legacy boolean-shape response alongside the new per-item integer-counts
path.

**Decision:** if there are **no prior clients** depending on the previous
behavior, remove it. Single canonical path is the right state.

**Rationale:**
- Less code to maintain (one Zod schema, one response shape, one dedup
  mechanism)
- Less documentation surface (one canonical path, not "canonical vs legacy"
  split that future readers have to disambiguate)
- Less test coverage spread (one path to validate end-to-end, not two)
- Less audit surface (one security and validation model)
- Less drift risk during ongoing work — when the canonical path evolves across
  Route A work items, only one path needs to keep up
- Less confusion for future developers ("why two shapes? what's the
  difference? when does each apply?")

**Concrete application:** in W1, wrapper-level `event_id` dedup is removed
entirely. Per-item is the only path. The plugin has always sent per-item
(`CatalogPayloadBuilder` places `event_id` inside each product object), so
removing wrapper-level doesn't affect the plugin — plugin is already aligned
with the canonical path.

**When this rule does NOT apply:** if a previous behavior has actual consumers
(existing customer integrations, deployed sister services, public API),
backward-compatibility may justify keeping a legacy path with a documented
deprecation window. Verify "no consumers" before applying this rule.

**Practical check before removing**: confirm with the human owner that no
clients depend on the to-be-removed path. The plugin-engine pair here had no
prior clients of the plugin ingest endpoints (production data flowed through
admin CSV upload, not plugin ingest), so wrapper-level had no consumer to
protect.

---

### CC-10: Upstream sync cadence — quarterly review of fork parent

**Context:** the plugin is a fork of `sendsmaily/smaily-wordpress-plugin`. The
upstream continues to receive commits — bug fixes, compat updates, occasional
security patches. The first upstream audit (`docs/audits/UPSTREAM_AUDIT.md`,
2026-06-03) found 14 commits accumulated since fork point, including 6 bug
fixes confirmed reproducible in our fork. Without a deliberate sync rhythm,
upstream fixes accumulate silently and the fork drifts further from a safe
reference.

**Decision:** review upstream commits on a **quarterly minimum** cadence,
with ad-hoc reviews triggered by:
- Security disclosures (CVE on WordPress / WooCommerce / a dependency)
- Major WordPress or WooCommerce releases (compat-fix likelihood)
- Pilot-client reports that match an upstream-fixed issue

**Process** (documented in `docs/audits/UPSTREAM_AUDIT.md` as a cumulative log):

1. `git fetch upstream`
2. List commits since the previous audit's HEAD: `git log <prev-audit-head>..upstream/main --oneline`
3. Categorize each commit:
   - 🔴 Security → cherry-pick (P0)
   - 🟠 Bug fix → cherry-pick if confirmed present in our fork
   - 🟡 Compat → discuss with project owner (versions, deprecations, etc.)
   - 🟢 Equivalent already in place → ignore, note in audit
   - ⚪ Not relevant → ignore, note in audit
   - 🔵 Style only → ignore (we have our own style)
4. For each 🟠 candidate, **verify the bug is present in our code** at the
   line level before recommending cherry-pick. Don't assume file existence
   means bug presence.
5. Audit document is committed first (pure audit, no code changes). Project
   owner reviews. Approved cherry-picks land in subsequent commits, each
   with `ci:strict` + integration tests passing.

**Rationale:**
- **Upstream bugs continue to affect our fork** until either (a) the affected
  legacy code is replaced, or (b) the fix is cherry-picked. Both are valid
  paths; the choice is per-feature, not blanket.
- **Audit-before-action** keeps the project owner in control of strategic
  drift — some upstream changes (PHP/WP minimum version bumps) intentionally
  conflict with our pilot-compatibility targets and must be rejected even when
  upstream considers them progress.
- **Cumulative log** in `docs/audits/UPSTREAM_AUDIT.md` (rather than ephemeral notes)
  means a new contributor or a future Claude session can read the entire fork
  history of sync decisions in one place.

**Phase 4 strategic question (deferred):** whether to retire the legacy
integration layer entirely (eliminates upstream dependency for those files) or
maintain coexistence (requires continued upstream sync). The Phase 3
architecture intentionally builds alongside legacy rather than replacing it
(F1-1); this question gets revisited when the new architecture is feature-
complete (post-Route-A, post-pilot).

**Consequence:** in the audit log, every cherry-pick decision is traceable to
a categorization and rationale. If a pilot regression later traces to a
known-but-unpicked upstream commit, the audit log shows whether that was an
explicit "ignore" decision or an oversight. Audit-first prevents both blind
adoption and silent omission.

---

## Phase 1 — Smaily-side infrastructure

### F1-1: Coexistence strategy (legacy + new side by side)

**Context:** the existing `sendsmaily/smaily-wordpress-plugin` (legacy
`Smaily_Connect\*` namespace) works and pilot clients use it. A new rewrite would
be **risky** (regressions) and **slow** (can't reuse the legacy WC integration).

**Decision:** the new `Smaily\Connect\*` namespace lives side by side with legacy
**in the same plugin**. Migration is gradual: new code takes features over one by
one, legacy stays for backward compat, gets removed in the Phase 4+ cycle.

**Rationale:** **reduces pilot-client risk** (the legacy path keeps working if the
new one breaks). Lets the new UI (React wizard) be built **in parallel** with the
existing WC integration.

**Alternatives rejected:**
- **Full rewrite as a new plugin** — risky, requires merchant handover
- **In-place refactor** — interrupts pilot-client work, high regression risk

**Consequence:** in late Phase 2 two bugs surfaced (2.H.4, 2.H.5) from the
`pre_update_option_smaily_connect_api_credentials` legacy hook crashing during new
REST saves. Fix: `REST_REQUEST` guard early-skip. **Phase 4 cleanup** replaces it
with a `current_filter()` / context-arg check.

---

### F1-2: DB Migrator + numbered SQL migrations (not one schema file)

**Context:** the plugin needs its own DB schema (event queue, backfill job,
automation mapping, rec event queue, rec visitor). It expands over time.

**Decision:** a `DB Migrator` class + numbered SQL migrations
(`0001_event_queue.sql`, `0002_...`, ...). The activation hook runs any missing
ones. Version stored in `wp_options` (`smly_plus_schema_version`).

**Rationale:** lets the DB schema **expand version by version** without redefining
the whole schema. The activation hook is idempotent (existing migrations are
skipped).

**Consequence + lesson (2.H.7):** `dbDelta` is **sensitive** — comments (`--`)
caused a column-recognition error and the table wasn't created. SchemaMigrationTest
(Phase 3 3.0) now pins table existence after activation.

---

### F1-3: Multilingual adapter pattern (WPML/Polylang/TranslatePress/SiteLocale)

**Context:** the WP ecosystem has **4 different** multilingual plugins, each with
its own API. The plugin must detect the language regardless of which one is active.

**Decision:** a `MultilingualAdapter` interface + 4 concrete adapters
(`WpmlAdapter`, `PolylangAdapter`, `TranslatePressAdapter`, `SiteLocaleAdapter`).
`EnvDetector` picks the active one, the plugin queries language through the adapter
API.

**Rationale:** **a new multilingual plugin only needs a new adapter**, not
`if (is_wpml()) ... elseif (is_polylang()) ...` across the plugin code. The adapter
pattern is the standard solution when runtime polymorphism isn't available natively.

**Alternatives rejected:**
- Support only one (Polylang) — shrinks the client base
- if-elseif at every multilingual touch point — grows the surface area to control

---

### F1-4: Action Scheduler over WP-Cron

**Context:** the plugin needs background processes (backfill, event flush, retries).
WP-Cron is WordPress's built-in, BUT it's **request-driven** (relies on visits) —
slow/irregular on a low-traffic site.

**Decision:** Action Scheduler (already a dependency through WC anyway, small
addition). It gives us:
- Real async (not visit-dependent)
- Retry mechanism built in
- Admin UI (Tools → Scheduled Actions) for observability

**Rationale:** **the dependency is already there** (Smaily Connect requires WC), AS
is production-grade and a debug tool exists.

**Consequence:** Phase 3 IngestFlusher uses an AS callback, retry logic is at the
AS level. Client-level retries are lowered (1-2 attempts) to avoid blocking the
AS worker with a 31-second in-request backoff.

---

### F1-5: Magic-link auth (not a password) for the wizard

**Context:** the plugin wizard needs user authentication against Smaily during
setup.

**Decision:** magic link via email (not a password). The same pattern as
Tuduaeg.ee.

**Rationale:** **UX** — the user doesn't have to remember a password. **Security**
— no password to compromise. **Smaily support** — the Smaily API supports magic-link
generation.

---

## Phase 2 — React wizard + Settings UI

### F2-1: React + Vite + Tailwind, not native WP-admin

**Context:** the wizard and Settings UI need to be modern, mobile-friendly, fast.
Native WP-admin (PHP render + jQuery) is slow to develop, hard to test, has a dated
look.

**Decision:** **React + Vite + TypeScript + Tailwind** for the whole UI. PHP only
renders the mount point + boot payload; React takes over.

**Rationale:**
- **TypeScript** — compile-time error checking, indispensable for a large UI
  codebase
- **Vite** — fast build, hot reload in development
- **Tailwind** — utility-first, consistent style system, no per-component CSS files
- **React** — component-based reuse, state management (reducer), Vitest tests

**Alternatives rejected:**
- Native WP-admin — slow, dated, hard to test
- Vue/Svelte — less common, fewer WP-ecosystem examples

---

### F2-2: Custom 14 primitives, not a UI library (shadcn/ui or similar)

**Context:** the Smaily brand style is its own (pink + dark navy). Third-party UI
libs (Material, Ant, Chakra) don't fit visually.

**Decision:** **14 custom primitive components** (Button, Input, Card, PillTab,
StepRail, etc.) in Tailwind style, matching Smaily brand colors and typography.

**Rationale:** **brand consistency** + **smaller bundle** than shadcn/ui (which
would pull in all of Radix-deps) + **full control** over UX details (animations,
focus states).

**Consequence:** the layered-input pattern (native `<input>` with `opacity:0` plus
a custom span on top) resolved the Phase 2 alignment bug (WP core `load-styles.php`
margin -0.25rem beat Tailwind by specificity).

---

### F2-3: Wizard-first architecture (`setup_completed` flag)

**Context:** Settings and the wizard are **two** edit UIs for the same config. A
user could open Settings directly and skip the wizard — but that assumes they know
what to configure at each step.

**Decision:** Settings is reachable **only** after the wizard's Finish
(`smly_plus_setup_completed = true`). Before Finish: a Settings URL **redirects to
the wizard**.

**Rationale:**
- **Onboarding design** — the user gets a guided experience before "100 settings"
  intimidation
- **Fewer support questions** — the wizard ensures everything required is
  configured, not "why doesn't it work?"
- **Settings = editing after onboarding**, not initial setup

**Alternatives rejected:**
- Settings always open — confuses a new user ("where do I start?")
- Wizard only on first install, hidden later — loses "Re-run wizard"

**Consequence:** `uninstall.php` (sub-PR 2.J) **must** remove
`smly_plus_setup_completed`, otherwise a reinstall doesn't trigger the wizard
(discovered in Erkki's staging test).

---

### F2-4: Progressive disclosure in Settings

**Context:** Settings has 5 tabs (Connection, Subscribers, WooCommerce,
Recommendations, Integrations). Not all are always relevant — Subscribers/WC/Rec
**require a Smaily connection**.

**Decision:**
- **Connection is always visible**
- **Subscribers/WC/Rec are locked** until `smailyConnection === 'success'`, with a
  banner "Smaily connection required"
- **Integrations is always visible** (info-only, doesn't require a connection)
- **Hash deep-link to a locked tab** bounces to Connection

**Rationale:** **reduces confusion** — the user doesn't see locked tabs they have
no context for. Natural flow: connect → configure (tabs unlock).

---

### F2-5: Continue saves every step (not just Finish)

**Context:** originally the wizard Finish was a **stub** — `redirect to Settings`,
no save. We discovered that all wizard flow data was lost, because nothing was
saving.

**Decision:** **every Continue click POSTs its step's payload** before navigating
to the next step. Finish gets simpler: it only sets `setup_completed = true` (all
data is already saved).

**Rationale:**
- **Resilient to interruption** — the user can close in Step 3 → Steps 1-2 are
  saved → on return they continue from Step 3
- **A simple mental model** — "this step is done"
- **Step 1 credentials save immediately** — Step 3 workflows-fetch can use them
  (if saving were on Finish, Step 3 wouldn't see the credentials)

**Alternatives rejected:**
- Save only on Finish — everything is lost on cancel
- Save on every field change — many network requests, bad perf, race conditions

**Consequence:** the wizard and Settings have **different save patterns**: the
wizard does Continue-save (linear onboarding), Settings does Save/Discard per tab
(editing). Both are right in their own context. The shared `buildTabPayload`
keeps the logic in one place.

---

### F2-6: Mode A/B/C multilingual (account mapping)

**Context:** a multilingual WP site can have a **separate Smaily account per
language** (Mode A), **one Smaily account for all languages** (Mode B), or a
**hybrid** (Mode C — a default account plus per-language overrides).

**Decision:** three explicit modes, the user picks one in wizard Step 1. The
backend resolves with an `accountKey` parameter for "which account for this data":
- Mode A: `accountKey = language-code` (`et`, `en`)
- Mode B: `accountKey = 'default'`
- Mode C: `accountKey = 'default'` + per-language override map

**Rationale:** **flexibility** — different Smaily merchants are organized
differently. Estonian market typically Mode A (separate campaigns per language),
larger markets typically Mode B (one brand account).

**Consequence:** `EnvDetector::accountKey($language)` resolves the mode logic, so
backend call sites query it rather than each doing their own if-elseif.
Consolidated in the `Settings\Credentials` value object.

---

### F2-7: Field-mapping standard — Smaily's official convention is canonical

**Context:** the plugin syncs WC user fields to Smaily (First name, Phone, Gender,
etc.). Every Smaily field has a **field name** — this must be **consistent** across
the WC plugin / Shopify app / rec engine, so that the same contact from different
sources doesn't produce duplicated fields.

**Decision:** the **Smaily official WC plugin's
(`sendsmaily/smaily-woocommerce-plugin`) convention is canonical**. We use its
field names: `first_name`, `last_name`, `store`, `user_phone`, `user_gender`,
`birthday`, `customer_id`, `customer_group`, `first_registered`, `site_title`,
`nickname`. Documented in `FIELD_MAPPING.md`.

**Rationale:**
- **Backward compat** — the pilot client's existing Smaily templates
  (`{{ first_name }}`) keep working
- **Cross-platform consistency** — WC + Shopify + rec engine speak the same field
  language
- **Upstream compatibility** — our plugin is a fork of the official one, syncing
  is easier

**One subtle detail:** `user_phone` and `user_gender` keep the `user_*` prefix
(even though `first_name` doesn't) — backward compat with WP-meta names and with
the pilot client's existing segments and templates. Compatibility wins over
consistency.

**Platform-specific fields are namespaced:** `wc_*` (WC-only), `shopify_*`
(future), `rec_*` (rec-engine derived). Cross-channel fields (first_name) are
shared, platform-specific fields are isolated.

---

### F2-8: Mode A "default account" logic

**Context:** in Mode A the user configures language-specific accounts (et, en).
But **what happens** when WC produces a contact without language information (e.g.
through a non-multilingual plugin)?

**Decision:** Mode A always has **one default account** (e.g. the first one
defined), and "language-less" contacts go there. A spec-error discovered through
Erkki's domain walkthrough — initially the agent assumed every contact must end
up in a language-specific account.

**Rationale:** **commercial reality** — WC doesn't always carry language context
(admin-created contacts, imported contacts, non-multilingual scenarios). Without a
fallback, contacts would silently disappear.

---

### F2-9: Layered-input pattern (checkbox/radio alignment)

**Context:** WP core `load-styles.php` sets a rule
`input[type=checkbox],input[type=radio] { margin: -0.25rem }`. This **beat
Tailwind by specificity**, breaking the plugin UI (checkboxes were offset).

**Decision:** the **layered-input pattern** — the native `<input type="checkbox">`
is `opacity:0; position:absolute` (hidden, but functional for accessibility), with
a custom span next to it for the visual checkbox (Tailwind style). A click on the
span triggers the native input's focus + click events.

**Rationale:** to **bypass WP-core CSS specificity** without `!important`.
**Accessibility is preserved** (screen readers see the native input). The visual
is under full control.

**Consequence:** the pattern is documented in `STYLE_MAPPING.md`, used for all
checkboxes/radios in the plugin.

---

### F2-10: Empty sync values are omitted (absent != empty)

**Context:** if a WC user has no `birthday`, how does the plugin send it to
Smaily? An empty string (`birthday: ""`) would erase the existing value in Smaily.
`null` may be ignored by the engine.

**Decision:** an **empty source value is omitted from the body** (the field is
**not** in the payload). Smaily semantics: absent (no field) != empty (the field
is there but blank).

**Rationale:**
- **Preserves existing data in Smaily** on re-sync (if the data disappeared on the
  WC side due to a bug, it isn't erased in Smaily)
- **Smaily's official convention** — the same pattern used by Smaily and other
  plugins

**Consequence:** the same logic carries into Phase 3 rec-engine PayloadBuilders
(CatalogPayloadBuilder omits empty `description`/`image_url`).

---

## Phase 3 — rec-engine integration

### F3-1: Integration test baseline BEFORE features (sub-PR 3.0)

**Context:** the late-Phase-2 staging-bugfix cycle burned ~20 iterations on
integration-boundary bugs (route 404, save/read mismatch, dbDelta crash). Phase 3
adds 10 new REST endpoints = 10× that risk.

**Decision:** **before any rec feature**, build a `tests/Integration/` suite in
real WP wp-env:
- `RestRouteRegistrationTest` — all endpoints register
- `SchemaMigrationTest` — tables + indexes exist
- `SettingsRoundTripTest` — read/write symmetry
- `BuildHashTest` — boot payload is correct
- `DebugLogCleanTest` — no PHP Fatal/Warning from our code
- `RecEngineConnectivityTest` — conditional (env flag)

**Rationale:** **every test references a concrete Phase 2 bug class** — investing
~1 day in 3.0 saves a week in Phase 3.

**Consequence:** must-pass CI gate (not informational) — avoids the "nobody checks
the green light" pattern.

---

### F3-2: EndpointRegistry declarative route list

**Context:** the Phase 2 backfill bug (2.H.7) happened because Bootstrap **forgot**
to register an endpoint. The test didn't catch it (because the test forgot too).

**Decision:** `EndpointRegistry.php` is a declarative route list. **Bootstrap loops
over it**. **The test reads the same list** (RestRouteRegistrationTest).

**Rationale:** **route 404 becomes structurally impossible**, not just "tested for."
One list, two consumers. If someone adds an endpoint to the registry but forgets to
update the Bootstrap code → a **compile-time contradiction**, not a runtime 404.

**Note:** the best architectural step in Phase 3. **Generalizes** to all shared
structures — when two places need to be in sync, **declare in one place** and have
both read from it.

---

### F3-3: Rec engine public + api-key auth (not Vercel SSO)

**Context:** the rec engine is on Vercel, by default behind **Deployment
Protection** (SSO wall). The pilot client can't bypass SSO (a machine client, not
a human).

**Decision:** rec engine production = **No Protection**. Security shifts to the
**api-key layer** (setup token → api-key exchange). Every endpoint requires
`Authorization: Bearer <api_key>`, except `POST /api/setup/exchange` (which uses a
one-time setup token).

**Rationale:** **the correct machine-auth approach** — same pattern as Smaily
(subdomain + username + password). Vercel SSO is a dev-protection (human login),
not a plugin-auth mechanism.

**Consequence:** a critical security ordering — **api-key auth must be enforced
BEFORE No Protection is turned on**, or there's a "public + open" window.
Documented in RECENGINE_TODO.md P0 (engine-team work item).

---

### F3-4: Setup-token UI = full URL, not just the token

**Context:** Step 4 UI needs a setup token. Two variants: the user pastes (a) just
the token or (b) the full URL `https://intelligence.smaily.com/setup/<token>`.

**Decision:** **the full URL**. The plugin parses it: host = base_url, what comes
after `/setup/` = token.

**Rationale:**
- **Less user error** — copying a whole URL is easier than slicing out a token
- **base_url isn't a CONST in code** — staging vs prod URL without a plugin update
- **Flexible** — the engine could one day move (new Vercel project, custom domain)

**Alternative rejected:** just the token + a CONST base_url — a hardcoded base_url
would break staging/prod switching and would require a plugin update on an engine
move.

---

### F3-5: Path constants centralized (Client.php `PATH_*`)

**Context:** sub-PR 3.1.2 path bug — the plugin called `/setup/exchange` (wrong),
the engine expected `/api/setup/exchange`. The ping path was already correct
(`/api/v1/ingest/ping`), but setup-exchange was wrong. **Inconsistency** — paths
weren't centralized.

**Decision:** **10 path constants** in `Client.php` (`PATH_SETUP_EXCHANGE`,
`PATH_PING`, `PATH_INGEST_*`, etc.). All plugin↔engine URLs from one place. No
inline strings.

**Rationale:** **resolves the path-mismatch pattern for good** — everything follows
the same `/api` pattern. 3.2+ ingest endpoints use the constants, not inline strings.

**Lesson (LESSONS.md §2.4):** the mock hid the path bug because the mock was built
around the same wrong assumption as the plugin. **A live test is the only thing
that catches a mock↔engine mismatch.**

---

### F3-6: Endpoints map preferred over constants (engine = source of truth for paths)

**Context:** the plugin gets an `endpoints` map from setup-exchange — engine-returned
URLs for every endpoint. The plugin could ignore it (use hardcoded `PATH_*`) or
prefer it.

**Decision:** the plugin **prefers the engine-returned endpoints map** over
constants. `RecEngineSettings::endpoints()['ingest_catalog']`, falling back to
`Client::PATH_INGEST_CATALOG` only when the map is empty (pre-setup-exchange).

**Rationale:**
- **Engine = source of truth for paths** — the right direction of dependency in a
  two-system design
- **Engine path migrations don't require plugin updates** — if the engine moves
  `/ingest/catalog` elsewhere, the plugin follows automatically
- **Fallback is a safety net** — if the map is empty (an early call before
  exchange), the plugin still works

---

### F3-7: Variant A idempotency (uuid on every ingest endpoint)

**Context:** the plugin event queue (`smly_rec_event_queue`) is **uuid-based
anyway** (migration 004 enforced `event_uuid NOT NULL UNIQUE`). Two options for
engine deduplication:
- (A) uuid on every endpoint (browse + catalog + customers + orders) — uniform
- (B) uuid only on browse, the rest use natural-key UPSERT (sku, email,
  external_order_id)

**Decision:** **Variant A** — uuid everywhere. The wire field name is `event_id`
(per the spec §6 browse pattern), the plugin side is `event_uuid` (queue column);
`PayloadBuilder` converts between them.

**Rationale:**
- **Queue model = wire model** uniformity (B would create a divergence)
- **Engine async pipeline race protection** — natural-key UPSERT races on async
  processing
- **Audit trail** — the engine can trace exactly which plugin event matched which
  engine row
- **Plugin-side genericity** — the same `PayloadBuilder` pattern for
  catalog/customers/orders

**Defensive layer (not XOR):** `event_id` is **an extra layer on top of natural-key
UPSERT**, not a replacement. The plugin sends `event_id` → engine uuid-dedup. The
plugin doesn't send it (old version) → engine natural-key UPSERT. **Both work.
Backward compat.**

**Per-item location in the body:** `{"items":[{"event_id":"...","sku":...}]}`,
not top-level wrapper. **Live-verified** in sub-PR 3.2.4 W/P diagnostic test
against the deployed engine (Variant P retry returned `{"deduplicated": 1,
"deduplicated_all": true}`; Variant W still returns the legacy boolean
`{"deduplicated": true}` until W1 wrapper-level removal completes per CC-9).

**Note on the 6/6 sanity test correction:** the engine team's pre-3.2 "6/6
sanity tests passed" report was retrospectively found to test wrapper-level
event_id (which Zod accepted) rather than per-item (which Zod silently
stripped). The actual per-item Layer-2 implementation arrived in Route A W1
(after the 3.2.4 plugin live test surfaced the silent-strip behavior). This is
why 3.2.4 live testing matters and why mock-vs-deployed must be validated for
spec examples too (LESSONS.md §2.4 applied to specs, not just code).

---

### F3-8: Sub-PR 3.2 scope = variant 3 (infra + catalog end-to-end)

**Context:** 3.2 could have been (1) all 3 endpoints together, (2) split into 3
sub-PRs, (3) only catalog + infra.

**Decision:** **variant 3** — IngestQueue + Flusher + HookHandler + AS jobs +
catalog end-to-end. Customers + orders in 3.3 (same pattern).

**Rationale:**
- **3.2 builds NEW infra** (first rec-engine data-ingest machinery) — first time =
  highest risk
- **Catalog end-to-end exercises the whole machine with ONE endpoint** — if an
  infra bug surfaces, it's isolated to the catalog path
- **Customers + orders are "the same pattern"** — 3.3 lands quickly after the
  catalog confirmation

**Alternative rejected:** all 3 together — would make it harder to isolate an
infra bug (is it in infra or in a specific endpoint?).

---

### F3-9: Mock for determinism, live for compatibility

**Context:** the rec-engine setup token is one-time → CI can't repeatedly run live
tests.

**Decision:** (see CC-7 above — cross-cutting). 3.2-specific: `RecEngineMockServer`
extended to catalog/customers/orders/browse endpoints. The live test
(`RECENGINE_LIVE=1`) uses the real api_key against the MiuMjau tenant.

**Consequence:** the mock server must **match the spec exactly** (same response
shape as the engine). LESSONS.md §2.4 pattern: if the mock diverges, integration
goes green but production breaks.

---

### F3-10: Layered Client retry strategy

**Context:** any HTTP call can fail (429, 5xx, network outage). Where to retry?

**Decision:** **two-layered retry**, each layer with its own context:
- **Setup-exchange + ping** (synchronous, user is waiting): Client does a full
  exponential backoff (1+2+4+8+16=31s, max 5 attempts, honoring Retry-After)
- **Ingest flow** (AS-job context): Client max-attempts **1-2**, row-level retry
  goes through the queue table's `next_retry_at` + AS re-tick

**Rationale:** an AS-job 31s block per failing call would **lock the AS worker**
the whole time → other jobs (email side) wait. The queue table is already a retry
mechanism (`next_retry_at`, `max_attempts`) — a Client-level retry would duplicate.

**Consequence:** retry logic depends on the Client constructor parameter (Flusher
passes `max_attempts=1`, others use the default 5).

---

### F3-11: api-key encrypted + never reaches the browser (proxy ping)

**Context:** the plugin stores the rec-engine api-key. The Step 4 UI needs a
"Test connection" button. Option (a) — React fetches the engine directly (api-key
in localhost). Option (b) — React calls a plugin REST endpoint, the plugin makes
the engine call server-side, React only sees the result.

**Decision:** **proxy ping**. React → `POST /wp-json/smaily-connect/v1/rec-engine/ping`
→ the plugin decrypts the api-key → calls the engine → returns status.

**Rationale:**
- **The api-key DOES NOT leak to the browser** — encrypted in DB, plain only in
  server-side memory
- **The boot payload `recEngine` block contains NO apiKey** — only tenant-display
  (connected, tenantName, engineVersion, etc.). React doesn't need the api-key.

**The security checkpoint** in 3.1 pinned all four: cipher-string in DB, setup
token NOT in `wp_options` after exchange, boot payload contains NO apiKey,
endpoints 403 without capability.

---

### F3-12: EnvSeed goes through the real `store(ExchangeResult::success())` path

**Context:** integration tests need a "connected" state as a fixture. One option —
direct SQL into the DB. Another — use the plugin code itself
(`RecEngineSettings::store()` with mock data).

**Decision:** **the plugin-code path** (`store(ExchangeResult::success(fixture-data))`).

**Rationale:**
- **Read/write symmetry is automatic** — the fixture goes through the same code
  path as the real setup-exchange, so if `store()` ever forgot a key, the
  fixture-test would expose it
- **Wp-env DB wipe protection** — the fixture is regenerated on every test-suite
  run, doesn't depend on persisted DB (LESSONS.md §2.6)

---

### F3-13: Systematic spec drift uncovered in 3.2.4 — Route A selected

**Context:** sub-PR 3.2.4 live testing surfaced a wrapper-key drift
(`products` → `items`), which triggered a broader engine-team audit. The audit
discovered the drift was systematic: the contract document was authored
aspirationally, the engine was scaffolded at commit `4ee73b1` with a narrower
implementation, and the two never converged. 6 drifts confirmed across catalog
(items wrapper, category_path non-empty, event_id location, dedup-not-impl)
and at minimum customers + orders (smaily_contact_id required, single-object
not batch, multiple silent-dropped fields).

**Decision:** **Route A** — engine grows to match spec; plugin design is
preserved. Production data for the MiuMjau pilot flows through admin CSV
upload, not plugin ingest, so engine convergence work doesn't disrupt the
pilot's existing data. After Route A complete, pilot migrates from CSV to
plugin sync (D4 in Route A plan v2).

**Rationale:**
- **Plugin design is built on spec** — variant A idempotency (F3-7), batch
  IngestQueue + Flusher (F3-8), per-item event_id (F3-7) are all aligned to
  spec. Route B (spec shrinks to engine reality) would force a plugin-side
  rewrite of design decisions made carefully across 3.0-3.2.4.
- **Pilot day-one capability matters** — campaign-link attribution requires
  `product_url`; sale-aware recommendations require `compare_price`. Route B
  would strip these from the plugin spec, reducing pilot business value.
- **Spec is the contract** — the spec was always v1.0.0; the engine never
  realized it. Route A "realizes" the contract rather than redefining it.

**Alternatives rejected:**
- **Route B** — spec shrinks to engine — loses pilot business value
- **Route C** — hybrid (pilot-critical engine builds, nice-to-have spec
  shrinks) — adds categorization overhead and creates "spec says X, engine
  does X minus N" ambiguity for future contributors

**Strategic decisions locked** (Route A plan v2):
- D1: Smaily UID = email (no `smaily_contact_id` required)
- D2: Variant 1 price semantics (`price` = current, `compare_price` =
  struck-through reference)
- D3: No feature-flag for W4 (clean one-way migration)
- D4: MiuMjau migrates to plugin sync, CSV retired (post-Route-A)
- D5: No pilot-live deadline pressure (quality before speed)

**Consequence:** plugin pause from 3.3 until W4 + W5 of Route A complete
(~2-3 weeks engine work). Plugin's 3.2.4 catalog-end ZIP is produced
independently (W1 already complete). The 6 contract drifts and their
resolutions become CHANGELOG entries on both repos.

---

### F3-14: Mock-vs-live applies to spec documents, not just code

**Context:** the 3.2.4 audit found that the contract document's example
payloads had never been validated against the deployed engine. The 6/6 sanity
test report from the engine team tested wrapper-level event_id (which Zod
accepted) and read as "Layer-2 works" without verifying per-item (which Zod
silently stripped) — the same pattern as the 3.1.2 mock-server divergence,
but applied to specifications rather than code.

**Decision:** specifications must be **live-validated** with the same
discipline as code. When a spec section is authored or revised:
1. Each example payload is run as `curl` against the deployed engine
2. The actual response (status + body) is pasted into the commit message or
   review report
3. Only after live-validation does the spec change get committed
4. Mock servers are then aligned to the live-validated behavior, not to spec
   text

**Rationale:**
- The LESSONS.md §2.4 pattern ("mocks reflect your assumptions, not reality")
  applies to specs too — they're another form of "writing down what you think
  the system does." Specs can drift from reality the same way mocks can.
- 4 (now 6) instances of mock-vs-real drift demonstrated the cost; the
  contract document drift made it 7. Pattern justifies the discipline.

**Consequence:** Route A's cross-cutting process (in plan v2) includes
"deploy-validate every spec example before commit." Plugin team applies the
same on its side when documenting plugin-internal APIs.

---

### F3-15: Single canonical event_id path — wrapper-level removed (Route A W1)

**Context:** see CC-9. The W1 implementation initially kept wrapper-level
`event_id` dedup as a legacy boolean-shape path alongside the new per-item
integer-counts canonical path. With no prior clients on plugin ingest
endpoints (production flowed through admin CSV upload), backward-compat is not
a constraint.

**Decision:** wrapper-level event_id support is removed entirely. Per-item is
the canonical and only path. Single Zod schema, single response shape, single
dedup mechanism.

**Rationale:** see CC-9 (less code, less docs, less audit surface, less drift
risk). The plugin already sends per-item via `CatalogPayloadBuilder`, so
removing wrapper-level has zero plugin-side impact.

**Live verification (3.2.4 walk):** per-item Variant P retry against deployed
engine returns `{"deduplicated": 1, "deduplicated_all": true}` (W1 working as
designed). The W/P diagnostic test stays in `walk-3.2.cjs` as structural drift
protection — if a future change re-introduces wrapper-only behavior or strips
per-item, the walk catches it immediately.

---

### F3-16: Catalog-end milestone — sub-PR 3.2.4 complete

**Context:** sub-PR 3.2.4 completes Phase 3 catalog-ingest. Plugin and engine
are aligned on catalog-end through the canonical per-item Layer-2 dedup, all
fields the engine accepts are populated correctly by the plugin's
`CatalogPayloadBuilder`, and the variant-product expansion works end-to-end.
Catalog ZIP is produced (`smaily-connect-2.0.0-beta.1-a75f096.zip`).

**Decision:** catalog-end is the **canonical reference** for Phase 3 ingest
implementation. Subsequent ingest sub-PRs (3.3 customers, 3.3 orders, 3.4
browse, 3.5 backfill) follow the same architectural pattern:
1. `PayloadBuilder` maps plugin domain → engine wire shape
2. `Client::ingest_*` sends to the deployed engine endpoint
3. `IngestQueue` carries `event_uuid` per row, dedup-protected
4. `IngestFlusher` AS-job sends batches with per-item event_id
5. Mock server matches deployed engine response shape (after live verification)
6. Walk script proves end-to-end against deployed engine before ZIP

**Live-verified scenarios (14/14):**
- Upsert end-to-end (hook → queue → flusher → engine)
- Resend idempotency (Layer-1 UPSERT + Layer-2 dedup)
- Batch 100 products → `processed: 100`
- Variable product → separate event_uuid per variation (2/2 sent)
- Delete → `catalog.delete` event reaches engine
- Partial-success → engine returns `400` for whole batch (all-or-nothing)
- No event_id → Layer-1 natural-key UPSERT (spec §7 backward compat)
- Wrapper Variant W and per-item Variant P diagnostic (W/P-flip captured)

**Consequence:** 3.3 customers + orders can mechanically follow the catalog
template once Route A W4 + W5 complete. The architectural decisions are
proven; the work is "fill in the per-endpoint mappings, apply the same
pattern, live-verify before ZIP."

---

### F3-17: Required-if-always-sent principle (W2 application)

**Context:** during Route A W2 (catalog field expansion), the engine team
flagged that `product_url` and `in_stock` were marked required in spec §3
but the engine Zod schema was lenient (`optional()` and `default(true)`
respectively). They asked whether plugin always sends these — if yes, tighten
the engine to match the spec; if not, loosen the spec to match the engine.

**Decision:** if the plugin can **always** send a field (no conditional skip
path, no edge case), the engine **should require** it. Defaults hide drift.

**Rationale:**
- **One truth, no silent fallbacks** — sister principle to CC-9 (single
  canonical path). A field that's "required in spec, defaulted in engine" is
  the same anti-pattern as "canonical in spec, legacy in engine": readers
  see two stories.
- **Loud failure over silent guess** — if `product_url` is missing for any
  reason, a 400 surfaces the underlying problem (e.g., a custom plugin broke
  `get_permalink()`); a silent `product_base_url + sku` fallback hides it
  until customer-facing email rendering goes wrong.
- **Plugin verification is cheap** — `CatalogPayloadBuilder` is auditable in
  seconds (unconditional array-literal block, doc-comment states "REQUIRED
  fields are always present"). The "always sent" claim is mechanically
  verifiable, not a hopeful assumption.

**Concrete application (W2):**
- `product_url`: required + non-empty (mirrors `category_path` strictness)
- `in_stock`: required (always a real boolean from `is_in_stock()`)
- Engine commit `967e142` tightened the schema; spec commit `645c4fa` synced
- Live-validated: missing → 400, empty string → 400 (product_url), both
  present → 200

**When this rule does NOT apply:** if the plugin has a legitimate conditional
skip path (e.g., a field that depends on plugin configuration the user can
disable), keep optional. The rule is "can the plugin always send" not
"should the plugin always send."

---

### F3-18: Per-item `errors[]` batch contract (D6) — decided

**Context:** with W2's all-or-nothing catalog validation, a single invalid
product in a 100-row batch fails the whole batch with 400, and the plugin
marks all 100 rows `mark_failed` — conflating 1 poison row with 99 healthy
ones. When the engine team raised the customers batch-error contract (Q1
before W4), this became a cross-team decision: should batch ingest be
all-or-nothing or per-item partial-success?

**Decision:** **per-item `errors[]` is the canonical batch-ingest contract,
unified across all batch endpoints** (engine-side decision D6). The engine
processes the good records, returns 200 with an `errors[]` array listing the
rejected ones. The plugin's flusher parses `errors[]` and splits the batch:
healthy rows → `mark_sent`, rejected rows → `mark_failed` / `record_attempt`.

**Canonical D6 response shape:**
```json
{
  "ok": true,
  "processed": 28,
  "deduplicated": 1,
  "errors": [
    {"index": 3, "email": "bad-email", "field": "email", "message": "Invalid email"}
  ]
}
```
Invariant: `processed + deduplicated + errors.length == total`. Error object:
`{index, <natural_key>?, field, message}` — `index` is batch position
(maps directly to the flusher's index-aligned `batch_rows[index]`), natural
key (`email`/`sku`/`external_order_id`) included when available, omitted for
browse (no Layer-1 natural key). Same shape on every endpoint, so the flusher
logic is identical regardless of endpoint.

**Rationale:**
- **One mental model, one code path** — a developer learns one error contract,
  not "catalog behaves one way, customers another." The flusher handles the
  same `errors[]` shape for every endpoint. Application of CC-9 (single
  canonical path) to error handling.
- **Partial-success serves transactional endpoints** — customers and orders
  are larger, more variable batches (backfill sends thousands; email data has
  more edge cases than product SKUs). One bad record shouldn't block 99 good
  ones.
- **Catalog all-or-nothing was an artefact, not a requirement** — the engine
  team audited it and confirmed: it came from whole-array `safeParse` + the
  W1 dedup transaction, not an atomicity need. No reason to keep it.

**Plugin-side cost (Code audit):**
- `ApiException`: small (~5-10 lines) — the response body is already
  JSON-decoded in `Client::request_url` and passed to the constructor; it
  just discards everything except `request_id`/`error_code`/`message`.
  Preserving `details`/`errors` is a small getter addition. **This is now a
  3.3 requirement, not a future candidate.**
- Flusher: medium — catch the return value, parse `errors[]`, map
  `index → queue-row`, split `mark_sent` vs `mark_failed`. Favorable fact:
  the flusher already builds `$products` and `$batch_rows` as index-aligned
  parallel arrays, so `errors[].index → batch_rows[index]` is a clean mapping.

**Sequencing (engine-side):**
- **W4 customers** is built to D6 from the start — the first endpoint to
  implement per-item `errors[]`, defining the canonical HTTP shape.
- **W5 orders** built to D6 when it lands.
- **Catalog + browse retrofitted together** (engine work item N-7) from
  all-or-nothing → per-item `errors[]` after W4 — proving the shape on a
  fresh build (customers) before touching the already-synced older endpoints.

**Correction to earlier framing:** browse was mis-categorized as "cleanest /
already per-item" in `docs/audits/MOCK_DIVERGENCE_AUDIT.md`; it is also all-or-nothing and
needs the same retrofit as catalog. The only existing partial-success
reference is the admin CSV path (`commitCatalog` → `import_errors`), not the
HTTP endpoints. (Audit doc to be corrected.)

**Alternatives rejected:**
- **All-or-nothing everywhere** (catalog's W2 model) — simpler engine
  validation, but conflates 1 bad row with N in the audit log, and forces a
  degraded per-row-retry fallback (100x HTTP) on the plugin to isolate the
  culprit. Per-item `errors[]` gives precise isolation in one request.
- **Per-endpoint inconsistency** (catalog all-or-nothing, customers per-item)
  — rejected after the consistency question: if per-item is better, apply it
  everywhere; two contracts means two mental models for no benefit.

---

### F3-19: Customers-end milestone — sub-PR 3.3 complete

**Context:** 3.3 customers ingest completes the second Phase-3 ingest endpoint,
following the F3-16 canonical 6-step pattern (PayloadBuilder → Client →
IngestQueue → Flusher → mock → live-walk). It is the first endpoint built to
the D6 per-item contract (F3-18) from the start.

**Decision:** customers-end is the **canonical D6 reference** for orders (W5)
and the catalog + browse N-7 retrofit. The `errors[].index → batch_rows[index]`
split in CustomerFlusher is the pattern those endpoints copy.

**Live-verified (walk-3.3, 10/10 against the real MiuMjau engine):** connected,
upsert end-to-end, Layer-2 `event_id` dedup (`deduplicated_all` on resend), D6
partial success (`{processed:1, errors:[{index:1, field:email}]}`), the
`processed+deduplicated+errors==total` invariant, batch all-sent, the
`customers` wrapper, and the builder's absent-not-empty omission (no nulls on
the wire). The walk caught a real datetime bug (→ F3-21).

**Consequence:** orders (W5) mechanically follows. Customers ZIP produced. The
chain is live-wired (hooks enqueue, AS schedules the flusher). **ZIP ≠
pilot-go-live** — like catalog-end, it's a proven artefact; the pilot installs
after W5.

**Known follow-ups (plugin-side N-7 + housekeeping):**
- **✅ Catalog-flusher D6 consolidation — RESOLVED in N-7.1 (see F3-23).** The
  catalog IngestFlusher now extends `AbstractD6Flusher`; an engine per-item
  rejection marks that row FAILED, not SENT (silent-loss class closed, lock
  lifted). Proven live (`flusher_d6_split_lock_proof`).
- **EVENT_* constant asymmetry (N-7).** Catalog event constants live on
  CatalogHookHandler, customer/order's on their Flusher. **Intentionally kept**
  after N-7 — the chosen abstract-base design (not a monolithic dispatcher) gives
  each flusher its own constants; the "dispatcher" that would have unified them
  didn't happen (F3-23). Cosmetic; defer or drop.
- **Flaky test.** `admin/src/hooks/useBackfillProgress.test.ts` (fake-timers
  race) flakes ~1 in several full ci:strict runs; passes in isolation. Fix
  with deterministic timer mocking.

---

### F3-20: Customer ingest enqueues every registered user (A-filter)

**Context:** which WP users should the customer hooks (`user_register`,
`profile_update`, `woocommerce_created_customer`,
`woocommerce_save_account_details`) enqueue as rec-engine customers?

**Decision:** **A-filter** — every registered user, no role check.

**Rationale:** both existing sync paths are broader than a role filter — the
legacy subscriber-sync keys on the newsletter opt-in, and the new email
HookHandler syncs every registered user — so a `customer`-only filter would be
narrower than both AND would drop custom-role shoppers (VIP, wholesale,
member). Guest buyers (no WP user) are captured by the W5 order path. Admin
"noise" is small and self-resolving (a user with no purchase history gets no
recommendations).

**Alternatives rejected:** B-strict (`customer` role only — misses custom
roles); B-broad (staff-role blacklist — a maintenance burden as new staff
roles appear).

---

### F3-21: Datetime on the wire — `Z` form via a shared IsoDate helper

**Context:** the engine validates every timestamp with a strict Zod
`.datetime()` that **rejects a numeric offset** — `2026-01-15T10:30:00+00:00`
(PHP's `'c'` format) fails as "Invalid datetime"; only the `Z`-suffix form
(`...Z`, contract §base) passes.

**Decision:** one `IsoDate::to_z(int $timestamp)` helper is the single source
for wire datetime formatting; every PayloadBuilder routes its datetime fields
through it.

**Rationale:** F3-1 single-source applied to datetime. Two builders formatted
independently (`first_seen_at`, `on_sale_until`) and both hit the `+00:00`
bug; orders (W5) adds several more datetime fields. One helper means the bug
cannot recur.

**Found via:** the 3.3.4 customers live-walk — the mock didn't validate the
datetime format, so integration was green and only the live engine surfaced it
(LESSONS §2.4, the same shape as the catalog products→items divergence).
`first_seen_at` was an active bug; `on_sale_until` a latent sibling — both
fixed.

### F3-22: Orders-end milestone — sub-PR 3.3 orders complete (W5)

> **Partially superseded:** the on-hold→processing mapping was reversed by
> F3-42 (on-hold is NOT a sale), and the amount serialization (net
> `get_total()` lines, `subtotal/qty` unit_price, ex-tax discounts) was
> superseded by PRO-1241 — all order money fields are GROSS per contract
> v1.4.0 §5. The pattern, batch wrapper, and the rest of the status mapping
> stand.

**Context:** the third ingest domain (orders), built on the F3-16 canonical
6-step pattern and the D6 per-item contract (F3-18), against the W5 orders
contract (batch `{orders:[...]}`, `customer_email` identity, required `status`
enum, `currency` default EUR, per-line `discount_amount`, async attribution).

**Decision:** OrderPayloadBuilder (WC_Order → §5 wire) + Client::ingest_orders
(batch 50, `orders` wrapper) + OrderFlusher (D6) + OrderHookHandler
(`woocommerce_order_status_changed` → enqueue iff the mapped status is non-empty
and actually changed). **WC status → engine enum mapping is Variant 2** (F3-22
status mapping): completed/processing/cancelled/refunded map direct; on-hold →
processing; pending/failed/draft/custom → `''` (skip — not ingested). The
mapping is necessary AND correct: the live engine **rejects a raw WC status**,
confirmed by the orders live-walk (12/12).

**Rationale:** mirror the customers/catalog ends so the shared queue + flusher
abstractions hold across all three domains. Status mapping centralizes the
WC↔engine enum translation in one public `map_status()` (reused by the flusher's
`row_to_object` skip-decision and the hook handler's change-gate).

**Alternatives:** Variant 1 (pass WC status raw, let the engine map) — rejected,
the engine's enum is strict and rejects unmapped values. Variant 3 (ingest every
status incl. pending/failed) — rejected, those are not meaningful orders for
recommendations.

**Relationships:** F3-16 (pattern), F3-18 (D6), F3-21 (IsoDate ordered_at),
F3-19 (guest-customer concern, RESOLVED by W5 engine auto-create). No format
surprises on the live-walk — the Z-form datetime + status mapping both validated
live on the first call.

### F3-23: Plugin-side N-7 — `AbstractD6Flusher` consolidation + W2 wrapper drift

**Context:** after three ingest domains each grew a near-identical D6 flusher
(batch flush, `errors[].index → batch_rows[index]` split, invariant check, AS
job). Catalog's flusher was still **all-or-nothing** — on a 200+errors[] it would
mark a per-item-rejected product SENT (silent loss). That was a **hard lock
condition** before pilot catalog go-live (STATUS.md).

**Decision (architecture):** extract a shared **abstract base**
`AbstractD6Flusher` holding the D6 flush skeleton; each domain subclass provides
`event_types()`, `batch_size()`, `endpoint_label()`, `send()`, `row_to_object()`
and keeps its **own** AS hook / group / recurring tick. Catalog's IngestFlusher
moved onto the base (all-or-nothing → D6), closing the lock. Proven against the
real engine by the catalog live-walk `flusher_d6_split_lock_proof` (a no-SKU
product comes back as a D6 per-item error → marked FAILED; the valid one SENT).

**Rationale:** one copy of the D6 logic (less drift surface, CC-9 single-source),
while preserving per-domain scheduling independence — the queue is event_type
scoped so each flusher drains only its own rows.

**Alternatives:** (a) three independent D6 copies — rejected, triplicated the
silent-loss-risk logic. (b) one **monolithic dispatcher-flusher** draining all
event types — rejected, it couples the domains' scheduling/back-off and loses the
event_type isolation. (i-b abstract base) was chosen over both (confirmed with
the human before coding). NOTE: this means the EVENT_* constant asymmetry
(catalog on its HookHandler, customer/order on their Flusher) is **intentionally
kept**, not unified — the "dispatcher" premise that would have unified it didn't
happen (STATUS deferred items).

**The W2 wrapper drift (caught here):** the N-7.1 catalog live-walk — the first
catalog live-request since W2 — `400`d on `products: Required`. The catalog wire
wrapper had flip-flopped: doc said `products`; 3.2.4 live probe found the engine
then wanted `items` (we switched); **W2 (engine `b5b1295`) renamed it back to
`products`** (clean break). The W2 sync updated the doc **but not the code**
(`Client::ingest_catalog` kept sending `items`), and the mock — still on `items`
— hid it for five syncs. Fixed in Client + mock + ClientTest. A full
drift-audit across all eight syncs confirmed the **wrapper was the only
plugin-breaking drift**: customers/orders were live-walked right after their
syncs (so verified), browse/identity/GDPR are not shipped (stub/PATH-consts, no
drift surface), and removed fields (discount_price, smaily_contact_id, consent)
are not sent. New lesson class: **a sync updates the doc, not the code** (LESSONS
§2.7; CLAUDE.md CC-8 note).

**Relationships:** F3-18 (D6 contract), F3-16/F3-19/F3-22 (the three domains
consolidated), CC-9 (single-source), LESSONS §2.4 + §2.7 (mock-vs-live, sync-vs-code).

### F3-24: Browse-beacon architecture (3.4) — server proxy, no queue, always-register + handler-404

**Context:** browse events are client-originated, anonymous, high-volume
storefront telemetry — a different shape from the server-originated ingest
domains (catalog/customers/orders). The contract mandates a server proxy: "the
API key must never appear in client-side code — a plugin-side server proxy is
required for browse events" (§ auth).

**Decisions:**

1. **Server proxy, mandated.** The browser POSTs same-origin to
   `POST /wp-json/smaily-connect/v1/beacon`; `BeaconEndpoint` decrypts the
   tenant api_key server-side and forwards to `/api/v1/ingest/browse`. Direct
   browser→engine is ruled out (api_key leak).

2. **No IngestQueue/Flusher — best-effort telemetry.** Browse does NOT use the
   F3-16 PayloadBuilder→Queue→Flusher pattern. The client buffers in-memory
   (30s window) and the proxy forwards synchronously (the Client's layered
   retry covers transient blips). A lost batch is acceptable; durable delivery
   (AS-backed queue + retry) is over-design for telemetry — unlike an order,
   which is a durable state-change. Deliberate F3-16 deviation.

3. **Abuse model is part of 3.4.0**, not deferred. The `/beacon` route is
   public (anonymous visitors) AND spends the tenant's api_key + engine quota,
   so an unprotected proxy is a real attack surface (spam → polluted engine
   data + cost on the tenant's account). Three layers: a hard gate (404 before
   any work when disabled), per-IP + per-session rate limits (REMOTE_ADDR only
   — XFF is spoofable; the session counter complements IP behind NAT), and
   server-side validation (event_type allowlist, event_id required,
   ≤100 cap, §6 field-whitelist). First violation → whole-batch 400 (our own
   client never emits an invalid event, so a violation is tampering — this is
   the abuse-filter stance, distinct from the engine's lenient per-item D6).

4. **`/beacon` is registered UNCONDITIONALLY; the handler hard-404s when
   disabled** (NOT conditional registration). Rationale: proving "route absent
   when disabled" needs a fresh `WP_REST_Server`, and re-firing `rest_api_init`
   mid-suite to rebuild it **segfaults wp-env** (bisected: BrowseProxyTest +
   RestRouteRegistrationTest together → exit 255 with no PHPUnit summary; each
   alone is green). Conditional registration would therefore be untestable
   (segfault) or need an isolated bare-server route-table check of low value —
   because the **security posture is identical**: a disabled `/beacon` runs one
   `is_enabled()` check then returns 404, doing zero work (no api_key, no engine
   call, no transient). To a client it is indistinguishable from an absent
   route. The only difference is the namespace index lists `/beacon` — a trivial
   info-disclosure (the route name alone, behaving as 404, is not exploitable).
   This is the standard WP-REST pattern (register unconditionally, gate in the
   handler). `/beacon` is therefore in `EndpointRegistry::expected_routes()`.

**Alternatives rejected:** conditional registration (§ point 4 — untestable +
no security gain); a durable browse queue (§ point 2 — over-design); direct
browser→engine (§ point 1 — api_key leak).

**Relationships:** F3-11 (proxy-ping, the api_key-server-side precedent),
F3-16 (the ingest pattern this deviates from), F3-18 (D6, which the engine's
browse response also follows), LESSONS §2.7 (the EventType 8→9 drift caught in
the 3.4.0 context audit).

### F3-25: Rec-engine backfill (3.5) — one ingest path, two triggers; enqueue + inline-flush

**Context:** the live hooks ingest only CHANGED records. A pilot needs the
EXISTING catalog/customers/orders backfilled into the engine once at setup —
traversing thousands of records. The engine has no backfill endpoint or
pagination (Appendix A): backfill uses the SAME ingest endpoints in batches;
cursor traversal is the plugin's job.

**Decisions:**

1. **One ingest path, two triggers.** Backfill does NOT get a parallel ingest
   path — it enqueues into the SAME `IngestQueue` and drains through the SAME
   `AbstractD6Flusher` (per domain) the live hooks use. So D6 error-split, retry
   backoff, and engine `event_id` dedup are reused, not reimplemented. A live
   hook enqueues one changed record; a backfill enqueues every record. Each
   domain's backfill `enqueue_record()` mirrors its HookHandler (catalog fans a
   variable product out to variation units, etc.).

2. **`AbstractBackfillJob` + per-domain subclass** (consistent with the N-7
   `AbstractD6Flusher` shape). The base owns the cursor/state/AS-tick/progress;
   the subclass owns `count_total()`, `fetch_ids_after()`, `enqueue_record()`.
   The legacy contacts `BackfillJob` is NOT refactored into it (it's
   users→Smaily-specific); both implement a shared `BackfillJobInterface` so the
   REST endpoint + AS tick drive them through one path. The
   `smly_plus_backfill_job` table's `(job_type, target)` UNIQUE key lets the
   rec-engine rows (`target = rec_engine`) coexist with the legacy
   `(contacts, smaily)` row — no schema change.

3. **Enqueue + inline-flush per batch** (decision (b), not enqueue-only).
   `process_batch()` enqueues a batch then flushes it inline before advancing
   the cursor. Two reasons: progress reflects records actually SENT (not just
   queued — enqueue-only would show 100% while the recurring flusher still has
   ~100 min of work), and the queue stays BOUNDED (each batch drained before the
   next is enqueued) instead of ballooning to thousands of pending rows. The
   tradeoff — backfill is batch-synchronous, slower than async enqueue-only — is
   fine for a one-time mass setup (true progress > speed). Rejected: (a)
   enqueue-only (misleading progress, unbounded queue); (c) direct Client calls
   (would duplicate the D6/retry logic).

4. **Resumable cursor, no freshness marker.** The cursor (last-seen id) is a
   real `WHERE id > cursor ORDER BY id LIMIT batch` (not offset, which shifts
   under inserts/deletes) saved in the row, so a timed-out/crashed tick resumes
   from the cursor — never restarts (proven:
   RecEngineCatalogBackfillTest::test_resumes_from_cursor_and_does_not_restart).
   No `_smaily_rec_synced_at` freshness skip (decision (i)) — engine ingest is
   an idempotent UPSERT, so re-sending is harmless; a skip-unchanged marker is a
   future re-run optimisation, not correctness (YAGNI).

**Relationships:** F3-16 (the ingest pattern reused), N-7 / `AbstractD6Flusher`
(the abstract-base shape mirrored + the flusher reused), F3-20 (the A-filter the
customers backfill will enumerate by). 3.5.0 ships the base + infra + catalog;
.1 customers, .2 orders, .3 admin UI + live-walk.

### F3-27: Identity merge (3.7) — anon→known on login, server-side, complementary to retroactive binding

**Scope correction (sourced, not assumed).** The roadmap one-liner read "same
person, two emails → two customer records until merge", implying a
customer↔customer merge. Contract §7 + the README say otherwise: identity/merge
is **anonymous-session → known-customer** binding. v1 has NO customer↔customer
merge (email is the natural key — two emails are two customers, full stop). So
3.7 binds an anon session's browse history to a known customer; it does NOT
reconcile two known emails. (F3-14 discipline against a wrong assumption — this
time the plan's, caught by checking the contract before building.)

**Decisions:**

1. **Complementary to retroactive binding, not a duplicate.** The engine binds
   an anon session's earlier browse_events to a customer AUTOMATICALLY when a
   browse event carries the email/visitor-token (§6 retroactive binding). That
   covers "browses after being identified". identity/merge (§7) is the EXPLICIT
   path for "logs in but generates no email-carrying browse event after" — the
   gap retroactive binding leaves.

2. **Server-side `wp_login`, not the client-side JS mergeIdentity.** Login is a
   reliable server signal (vs a JS flag), the api_key stays server-side (no new
   public proxy route — `Client::merge_identity` posts directly), and the
   anon-session / visitor-token cookies are client-set but NOT HttpOnly, so
   $_COOKIE has them on the login request. The JS `mergeIdentity` stub is KEPT
   (still throws) and reserved for the Milestone-2 platform-agnostic client
   (Shopify) — YAGNI on the M2 path in v1.

3. **Checkout (`email_provided_at_checkout`) is NOT a trigger — timing, not
   redundancy.** Checked: order ingest does NOT bind the session's browse
   history — §5 uses `session_id` for attribution matching only ("stores the
   signals; a cron computes rec_attribution"), and the §6 retroactive binding is
   browse-endpoint-only. So a checkout merge would NOT be redundant. BUT a
   guest's customer is auto-created by the ASYNC order ingest, so it isn't in
   the engine yet at checkout — a synchronous merge then 404s. A registered user
   logging in already exists (A-filter ingested them at registration/backfill),
   so login timing is sound. Checkout merge is therefore deferred (the guest's
   history binds via the next email-carrying browse event anyway).

4. **404 customer_not_found → log + skip** (not ingest-then-retry). Rare (the
   A-filter ingests every registered user, so by login the customer exists), and
   retroactive binding is the safety net. Plugin-side **dedup** via user meta
   (`_smaily_rec_merged_anon_sid` = the last anon session merged for a user):
   repeat logins on the same session don't re-hit the engine (the merge is
   idempotent there anyway); a NEW anon session re-merges.

**Relationships:** §6/§7 (retroactive vs explicit binding), F3-20 (the A-filter
that guarantees the customer exists by login), F3-21 (IsoDate for merge_ts),
F3-14 (contract-vs-assumption check). 3.7.0 ships the handler; 3.7.1 the
live-walk + ZIP.

### F3-28: GDPR (3.8) — WP Privacy API, conservative export / complete erase, decision-logic strip

**Scope authority is `docs/DATA_MODEL_GDPR.md`** (a standalone doc) — the code
references it and does NOT re-derive the boundary. Summary of the decisions it
fixes, as applied in 3.8:

1. **Native WP Privacy API integration.** Register a
   `wp_privacy_personal_data_exporters` exporter (Art 15) + a
   `wp_privacy_personal_data_erasers` eraser (Art 17), so the rec data shows up
   in WordPress's own Tools → Export / Erase Personal Data — no bespoke UI.

2. **Export is conservative; erase is complete (asymmetric).** EXPORT surfaces
   only rec-specific PERSONAL data: engine browse_events / visitor_tokens /
   recommendations / email_events, the engine customer record MINUS its
   decision-logic fields (segment / RFM / engagement / inferred_* — the engine's
   classification, a trade secret, like Google/Meta withhold), and the plugin's
   own `_smaily_*` rec-meta. It does NOT re-export WooCommerce order/purchase
   data (Woo's own exporter owns that — the plugin reads rec-meta OFF an order,
   never the order), and NOT `rec_attribution` (the engine omits it from §8 —
   silently, NOT "request separately", because it's decision logic, not a
   subject-access right). ERASE is the opposite — engine §9 deletes everything
   (CASCADE incl. rec_attribution + visitor_tokens) and the plugin removes its
   `_smaily_*` markers.

3. **The WC boundary is the headline guarantee.** The exporter pulls rec-meta
   off an order (`$order->get_meta('_smaily_rec_id')`) but never the order's
   totals / line items. A test proves it: a customer with an order → the export
   contains `_smaily_rec_id` but NOT `total_amount` / `line_total`.

4. **HPOS-safe meta.** The order rec-meta MUST be read/written via the WC_Order
   object (`$order->get_meta` / `delete_meta_data` / `save`), NOT
   `get_post_meta` / `delete_post_meta` — under HPOS (the wp-env + WC-10.7 mode)
   order meta lives in `wc_orders_meta`, so the post-meta calls would silently
   read/erase nothing. (Caught by PHPStan flagging the `wc_get_orders('ids')`
   cast — a real HPOS bug, not just a type nit.)

5. **Opt-out (Art 21) is engine-API only in 3.8.** `Client::customer_opt_out`
   (§10) ships now; the trigger — Smaily profiling-consent withdrawal + the
   beacon's two-gate stop (browser-cookie consent AND Smaily profiling consent)
   — is a SEPARATE later piece, because it depends on the Smaily profiling-consent
   parameter API. 3.8 ships the mechanism the consent wiring will call.

6. **GDPR endpoint URLs use a `{email}` path placeholder — substitute with
   `str_replace`, never `sprintf`** (3.8.1, caught by the live-walk). The engine's
   endpoints-map advertises §8/§9/§10 as `…/customer/{email}/export` etc. The
   email goes in the URL *path* (rawurlencoded), and the substitution token is
   `{email}`, NOT `%s`. 3.8.0 shipped `sprintf(resolve_url(…), rawurlencode($email))`,
   which is a no-op on a `{email}` URL → the literal `{email}` reached the engine
   (404 `No customer with email '{email}'`). The unit + mock endpoints maps had
   used `%s`, mirroring the bug, so every gate was green; only the live engine
   used `{email}`. Fix: `Client::customer_url()` does `str_replace('{email}', …)`
   (fallback `PATH_CUSTOMER_*_TMPL` constants carry `{email}` too); the mock + unit
   maps now use `{email}` and the mock customer routes 422 on a literal-placeholder
   email so a regression fails integration. This is the placeholder-syntax-as-wire-
   shape lesson (LESSONS §2.9), a sibling of the `items`/`products` wrapper drift.

**Relationships:** DATA_MODEL_GDPR.md (the scope authority), F3-20 (the A-filter
customer the export/erase keys on by email), F3-27 (the merge-marker user-meta
the eraser also clears), §8/§9/§10 contract, LESSONS §2.9 (placeholder drift).
3.8.0 ships the handler; 3.8.1 the live-walk (10/10) that caught the placeholder
bug, + ZIP. Smaily profiling-consent wiring + beacon-stop follow separately.

### F3-29: Step 4 activation (3.9) — connect ⇒ sync all (system-decides); per-domain sync toggles removed

**Context:** Step-4 4a (the connected state) shipped five `recEngineFeatures`
checkboxes built faithfully to PLUGIN.md §Step-4-4a (sync orders/customers/products
+ track cart events, default-on; track browsing, default-off). But the backend
**never enforced** four of them — the Catalog/Customer/Order HookHandlers gate on
`is_connected()` alone, and the four option keys (`smly_plus_rec_sync_*`,
`…_track_cart_events`) were write-only with no consumer. Only `track_browsing` was
read (by the beacon). So the toggles were cosmetic, and `hydrate.ts` even hardcoded
them rather than reading the saved values. A spec/vision audit (the research that
preceded 3.9) surfaced the contradiction: PLUGIN.md specced toggles, but Erkki's
product vision had evolved to "activating the engine syncs everything — the system
decides; partial sync just makes a worse engine."

**Decision:** Adopt the vision. Connecting the rec-engine **syncs all domains
unconditionally** (the existing `is_connected()` gate IS the model). Remove the four
sync toggles from the UI + types/reducers/hydrate, stop persisting their options,
and clean up the dead keys (`Activation::cleanup_removed_rec_feature_options()`,
idempotent `delete_option`×4 on upgrade-detect). The **browse-tracking toggle stays**
— it's a legitimate **legal-consent gate** (opt-in, default-off), not a sync
preference, and is layered on top of end-user consent (WP Consent API / CookieYes,
the two-gate model of 3.4.2). Cart events ride the browse beacon (gated by
`track_browsing`), so there is no separate cart toggle. PLUGIN.md §Step-4-4a + §6
revised to follow the vision (the authoritative spec now matches).

**Disconnect / re-connect (Erkki-locked):** `disconnect()` clears only the
connection options (`smly_rec_*`: api_key, base_url, tenant, endpoints, config,
connected) — it does **NOT** delete `smly_plus_rec_track_browsing`. The ConnectedView
(and with it the browse toggle) hides because `is_connected()` is false; on
re-connect the saved browse preference is restored. This **required a hydration
fix** (no longer optional): `EnvDetector::rec_engine_snapshot()` now emits the saved
`track_browsing` (read independent of connection state), and `hydrate.ts` reads it
instead of hardcoding `false`. Without it, re-connect would forget the preference
(and a plain reload blanked a saved-on toggle) — so the hydration fix is the
*precondition* for the disconnect/re-connect decision, not a side cleanup.

**Alternatives rejected:** (a) wire the four toggles to actually gate ingest
(per-domain control) — rejected: contradicts the system-decides vision, and partial
sync degrades the engine; (b) leave the toggles as cosmetic UI — rejected: a UI that
promises a gate it doesn't have is a lie. The placeholder "mode-A → mode-B" label in
the old roadmap stub was never defined in any spec; it is NOT the multilingual
Mode A/B/C (F2-6, a separate email-account axis) nor "Route A" (the engine-convergence
plan) — the audit established this from sources.

**Relationships:** F2-6 (multilingual Mode A/B/C — the unrelated same-named axis),
3.4.2 two-gate consent (browser-cookie consent AND merchant preference), PLUGIN.md
§Step-4-4a + §6 (revised to match), the `is_connected()` ingest gate the
HookHandlers already use. Browse end-user consent (CookieYes / WP Consent API) is
unchanged — remembering the merchant preference does not bypass it.

### F3-30: Pilot-hardening — diagnostics + recovery system (3.10, implements §13/§13a)

**Context:** the production-readiness audit (after Phase-3 feature work) found two
operational pilot-blockers that aren't feature gaps: **P1** — a terminal `failed`
queue row is invisible and never re-driven (the rec queue, unlike the legacy
Smaily queue, has no retry-failed mechanism, and even that only re-drives
`pending`, not terminal `failed`); **P2** — no surfaced diagnostic trail
(`error_log`-only; `last_error` written but never read back; the backfill panel
reports records *walked*, so it read "1400/1400" while rows silently failed). These
are "how do you operate it in a pilot" gaps, more impactful than the (low-risk,
now-closed) legacy-backfill path.

**Decision:** build the spec's already-designed §13 (Event Log) + §13a
(NotificationManager) for the rec queue, **layered** across three audiences
(merchant "is it syncing?", developer "why not?", proactive "something broke"), as
ordered sub-PRs — visibility is the foundation (you can't recover what you can't
see; the "Retry now" button lives inside the Event Log):
1. **3.10.0 — visibility (P2):** read-only `/events` UNION read-model over both
   queues + Event-Log Settings tab + sticky failed-24h banner + backfill progress
   fixed to engine-confirmed sent/failed. No schema change (the columns exist;
   sent/failed counted at read-time from the queue since the run's `started_at`).
2. **3.10.1 — recovery (P1):** `IngestQueue::reset_failed()` (FAILED→PENDING) +
   `/events/retry` + a manual "Retry now / Retry all failed" button. **Manual-only
   base** — auto-retry is deferred because auto-retrying a deterministic 4xx loops
   forever; it needs failure-kind classification, which 3.10.0 already enables
   (`last_error` carries `http_4xx`/`http_5xx`/`d6_item_error`).
3. **3.10.2 — proactive admin-notice (Layer 3 base):** `NotificationManager` notice
   level on health signals (failed-count > N in 24h, engine-down > 1h). No infra.
4. **3.10.3 — email (post-pilot):** §13a email level via **`wp_mail`**, NOT Smaily.

**Email-channel decision (wp_mail over Smaily):** routing the alert through Smaily
couples it to a system that may itself be down — a "Smaily connection failed" alert
can't send via Smaily. `wp_mail` is independent (and is what §13a specs). The
trade-off is server-SMTP reliability, mitigated by the admin-notice base (immune to
mail failure, always visible in wp-admin) + documenting an SMTP-plugin recommendation.

**Relationships:** PLUGIN.md §13 (Event Log) + §13a (NotificationManager) — the
spec authority; F3-18 (the D6 queue's status/attempts/last_error the Event Log
reads); the legacy `smly_plus_retry_failed_events` job (the pattern 3.10.1 fixes —
it re-drives pending, not terminal failed). Pilot-hardening order: P5 → 3.10.0 →
3.10.1 → 3.10.2 → P4 (onboarding doc); 3.10.3 + queue-janitor + GCM are post-pilot.

### F3-31: Profiling consent — opt-out model (default-on), bidirectional Smaily sync

**Context:** the rec-engine profiles Smaily contacts; GDPR requires a consent basis
for profiling. Authority is Smaily (RECENGINE_API_CONTRACT line 722 — consent is NOT
in the rec-engine contract); the plugin writes the consent to a Smaily contact and
reads it back. Spec: `docs/SMAILY_PROFILING_CONSENT_SPEC.md` (the scope authority).

**Decision (Erkki, 2026-06): OPT-OUT model, default-on.** Profile a contact UNLESS
they've explicitly opted out. Enforcement (the inverse of the original opt-in draft):
`profile IF is_unsubscribed != "1" AND smaily_rec_profiling != "0"` — don't-profile
only on the general unsubscribe (stronger) OR an explicit profiling opt-out; a
missing field / contact-not-in-Smaily (206) → profile. Read-back values are Smaily
**strings** ("0"/"1") → string comparison. A read-back error **fails open** (profiles).

**Rationale:** Estonian AKI does not currently require a separate explicit profiling
**opt-in** — it requires a *transparent action* + a *working opt-out*. So default-on
+ a real opt-out path is lawful today. This is a **conscious decision carrying a
small GDPR risk** that Erkki is investigating separately before any opt-in TODO;
mitigated by transparency (privacy policy mentions profiling) + the working opt-out.

**Two consequences:**
- The **WP opt-out UX ((a).2) becomes a GDPR requirement, not a refinement** — the
  opt-out model is only lawful if the shopper can actually opt out.
- **TODO — explicit opt-in if AKI tightens.** `ProfilingConsent::is_allowed()` is
  built pure + invertible: flip to `profile IFF smaily_rec_profiling == "1"`.

**Probe-confirmed mechanism ((a).0):** write via the existing `upsert_subscribers`
(form-encoded, custom fields auto-create) → `{code:101}`; read-back via
`GET /api/contact.php?email=` returns `is_unsubscribed` + `smaily_rec_profiling`
(206 on miss). Smaily rejects `.test` email domains on write — a live-test gotcha,
not a code issue (no latent bug in the contact sync). The probe applied the project
scar "don't assume a wire shape — live-probe it" and caught that my own spec had
*assumed* read-back without verifying — the read-back endpoint was the one real risk.

**Fail-open risk (part of Erkki's separate GDPR review):** a read-back error returns
*profile* (fail-open), consistent with the opt-out/default-on model. The narrow
consent-risk window is: cache-miss **AND** read-error **AND** the contact had
actually opted out — only then is someone profiled briefly without consent. Bounded
by the cache (a WP-side opt-out sets cache `'0'` immediately; a known opt-out stays
cached for the TTL, so the window needs a *cache-expired* opt-out + a *concurrent*
read failure). Narrow + transient, but real — folded into Erkki's separate GDPR
investigation alongside the opt-out-model risk.

**Fail-open window hardened (PRO-1194, 2026-07-13).** The investigation
(`docs/DATA_MODEL_GDPR.md` "Fail-open GDPR window") concluded: keep the
fail-open default, but harden it with serve-stale-on-error + a durable
opt-out registry so a read error can never re-allow a contact whose opt-out
the plugin has already seen. Implemented as-designed; the residual fail-open
now covers only a genuinely never-seen contact whose first-ever read errors.
See that doc's "Implemented behavior" table for the final four-layer shape.

**Enforcement runtime (3.10.x-style):** cached read-back (`smly_profiling_*`
transient, **daily TTL** — a Smaily-side opt-out propagates within a day; profiling
isn't real-time-critical, but a week would be a GDPR problem). WP-side opt-out writes
update the cache immediately. OFF → engine §10 `customer_opt_out` (3.8) + beacon-stop
((a).1). Cookie two-gate: the beacon sends IFF browser-cookie-consent (CookieYes,
ePrivacy) AND `smaily_rec_profiling != "0"` (this gate) — profiling default-on means
the beacon depends mostly on the cookie gate, plus the opt-out check.

**(a).1 — beacon gate placement + two design calls:**
- **Gate placement:** the profiling gate is at the **`BeaconEndpoint` proxy**
  (server-side), filtering events whose `customer_email` resolves to opted-out
  before the forward. Anon events (no email) have no contact to check → only the
  cookie gate applies (until identified). may_profile() reads the cache (one check
  per batch — a batch is one visitor), so a read-back is at most once per
  contact-per-TTL in the proxy path.
- **Drop semantics (Erkki):** a profiling-opt-out drop is a **conscious drop, not
  an error** — aggregated into a 24h counter (`smly_profiling_dropped_24h`, for a
  future surface) and logged **once per batch** (never per event, so a heavy
  opted-out browser can't flood the log). Beacon events aren't durable-queue rows,
  so they don't appear in the Event Log — surfacing the drop count is a possible
  refinement, not built.
- **Anon→known retroactive (Erkki):** when an opted-out shopper logs in,
  `IdentityHookHandler` **skips `identity.merge`** — so the engine never
  retroactively binds their prior anon browse to their identity. The opt-out is
  respected **backwards**; the anon events stay unattributed (not deleted — the
  spec's "do not profile" = engine §10 opt-out + don't-bind, not erase).

**Relationships:** SMAILY_PROFILING_CONSENT_SPEC.md (authority), F3-28.5 (the
deferred piece this is), §10 `customer_opt_out` (3.8, the engine-side enforcement),
3.4.2 two-gate consent (the cookie gate this layers onto). Sub-PRs: (a).0 Client
read/write + `ProfilingConsent` enforcement (this); (a).1 beacon two-gate; (a).2
live-walk + WP opt-out UX.

### F3-32 — Cypher v2: AES-256-GCM, versioned blob, upgrade re-encryption (audit fix)

**Context:** FABLE_AUDIT §4#2 — the legacy AES-256-CBC blob used a STATIC IV
(= a prefix of `AUTH_KEY`) for every encryption and persisted that IV inside the
stored value: every DB dump leaked an AUTH_KEY prefix, and equal plaintexts
produced equal ciphertexts. A CBC→GCM upgrade was already in BACKLOG (post-pilot).

**Decision:** `Cypher::encrypt()` now writes ONLY the v2 format — `smy2:` +
base64(nonce(12) ‖ tag(16) ‖ ct), AES-256-GCM, random per-message nonce, key =
raw-binary sha256(SECURE_AUTH_KEY). `decrypt()` dispatches on the prefix and
keeps a behaviour-identical legacy CBC+HMAC read path (decrypt-only, never
written). `Activation::reencrypt_legacy_secrets()` (runs inside `run()`, so on
activation AND upgrade-detect, idempotent via the prefix check) migrates all
three storage locations: the legacy default credentials array, every
`smly_plus_credentials_*` account, and `smly_rec_api_key` (autoload=false
preserved). An undecryptable blob (rotated salts) is left byte-identical —
overwriting would destroy the "re-enter your credential" evidence.

**Rationale:** any IV fix forces a stored-format migration; going straight to
GCM costs ONE migration instead of two and closes the BACKLOG item. AEAD also
retires the hand-rolled encrypt-then-MAC.

**Alternatives:** random-IV CBC (same migration cost, keeps the hand-rolled
HMAC); lazy re-encrypt-on-save only (leaves leaking blobs in the DB
indefinitely on installs that never re-save).

**Relationships:** FABLE_AUDIT §4#2 + remediation log; BACKLOG GCM item
(closed); `CypherGcmTest` (integration — the REAL class; the unit suite runs a
spy shim, see tests/bootstrap.php).

### F3-33 — Queue janitor: terminal-row retention prune + created_at index (audit fix)

**Context:** both durable queues grew without bound (sent/failed rows never
pruned), and the rec-queue had no index a `created_at` range scan could use —
FABLE_AUDIT §5 watch-item; the BACKLOG "Queue janitor" item was pulled forward
pre-pilot (Erkki: months of pilot rows would make this expensive later).

**Decision:** `DB\QueueJanitor` — a daily Action Scheduler tick
(`smly_plus_queue_janitor`) deletes terminal rows past retention from BOTH
queues: `sent` after 30 days, `failed` after 90 days (both filterable:
`smaily_connect_janitor_{sent,failed}_retention_days`). **`pending` rows are
NEVER pruned regardless of age** — they are work, not history; an old parked
retry must survive until it terminally resolves. DELETEs run in LIMIT-1000
batches, max 20 per status per run (a neglected table drains over consecutive
daily ticks instead of one table-locking statement). Migration 006 restates
both CREATE TABLEs with `KEY idx_created_at` (the dbDelta-supported way to add
an index).

**Rationale:** failed rows keep a long window because they are the Event Log's
diagnostic evidence and stay retryable until pruned; sent rows are
engine-confirmed and only useful as a short audit trail.

**Alternatives:** prune on flush (couples housekeeping to the hot path);
TTL-partitioned tables (overkill at this scale); pruning failed fast (destroys
the recovery story 3.10.1 just built).

**Relationships:** FABLE_AUDIT §5/§7#9 + remediation F6; 3.10.0 Event Log
(failed-in-24h count also benefits from the index); `QueueJanitorTest`
(3 integration tests: retention matrix incl. pending-never, filterability,
index presence).

### F3-34 — RSS feed URL builder relocated to Integrations (client-side, stateless)

**Context:** the pilot's old plugin (1.6) had an RSS settings tab; the new UI
seemed to have dropped the feature. Investigation (2026-06-12): the feed itself
never stopped working — the legacy `Rss` class registers its rewrite + template
whenever WC is active, all parameters travel in the feed URL's query string.
Only the settings UI vanished, as a **side-effect** of the 2.H.3 legacy-menu
hide (the RSS tab lived in the hidden menu); the React UI never got a panel.
Existing template URLs at the pilot keep working unchanged.

**Decision:** rebuild the URL builder as `RssFeedSection` under the
**Integrations** step/tab (Erkki's placement call — not data-sync, not
automations; both wizard Step 5 and Settings, same component). **Purely
client-side and stateless**: the old tab's options were only ever prefill for
a URL generator, so the new section saves nothing — no save-footer, no
dirty-tracking, no REST changes; Integrations stays info-only. Server side,
`EnvDetector::rss_snapshot()` emits a `rss` boot-payload block (permalink-aware
base URL via legacy `Rss::make_rss_feed_url()`, `product_cat` terms, legacy
option values as prefill — a migrated store sees its old configuration); null
when WC is inactive, which hides the section. `buildRssFeedUrl()` mirrors the
legacy admin.js param contract byte-for-byte (incl. the `order_by=none` omits
`order_by`+`order` quirk).

**Rationale:** the URL is the artifact the merchant copies into a Smaily
template; persisting builder inputs adds a save path for zero behavioural
gain. Prefill-from-legacy covers migration continuity.

**Alternatives:** RSS panel with a real save path (rejected — would force
dirty-tracking onto the info-only tab for no benefit); un-hiding the legacy
tab (rejected — reverses 2.H.3); leaving it URL-only/undocumented (rejected —
the pilot merchant can't discover the feature).

**Relationships:** 2.H.3 (legacy-menu hide — the cause); `EnvDetectorTest`
(unit: null-gate + WC-active subclass path), `RssBootSnapshotTest`
(integration: pins that the legacy classes are actually loaded in a real env —
the seam the unit suite must fake), `rss-feed-url.test.ts` +
`RssFeedSection.test.tsx` (vitest).

### F3-35 — Version renumbered 2.0.0-beta.1 → 2.1.0-beta.1 + `Update URI` header (upstream-collision guard)

> **⚠️ `Update URI` header REMOVED 2026-07-23 (Erkki's direction, ships in
> v3.9.0), ahead of the sendsmaily upstream merge.** The renumbering half of
> this decision (2.1.0-beta.1, and the general discipline of staying
> monotonically above upstream's released version) stands unchanged. Only
> the header guard is retired. **Rationale:** WP core never offers a LOWER
> version than what's installed — the wordpress.org listing was frozen at
> 2.0.0 while this fork is 3.8.1+, so the clobber vector the header guarded
> against (§ below) cannot fire regardless of the header's presence; the
> header's only remaining effect was to also block the fork from EVER
> picking up a legitimate wordpress.org update once sendsmaily publishes a
> version ≥ the installed one. Removing it now is the intended migration
> mechanic for the upstream merge: stores transition automatically to the
> wordpress.org update channel the moment sendsmaily publishes a release at
> or above the fork's version, with no separate cutover step needed later.
> See `docs/UPSTREAM_MERGE_PROPOSAL.md` checklist item 2 (now done) and
> STATUS.md for the release this rides in.

**Context:** preparing the first GitHub release surfaced that upstream
(sendsmaily) shipped its own **2.0.0** to wordpress.org on 2026-06-03 (#128 —
a 1.x-line WP-7.0 compatibility bump, ~2000+ active installs; verified live on
w.org). Our fork installs into the same `smaily-connect/` folder, so WP's
update check matches the w.org slug and compares versions:
`2.0.0-beta.1 < 2.0.0` → the pilot site would be OFFERED upstream's package —
or, with per-plugin auto-updates on (likely carried over from the 1.6
install), silently REPLACED by it mid-pilot, deleting the entire rec-engine
codebase from disk. UPSTREAM_AUDIT had tracked #128 only as a min-versions
conflict; the auto-update clobber vector was a new find.

**Decision:** two independent guards, both in the same commit. (1)
**`Update URI: https://github.com/erkkimarkus/smaily-wordpress-plugin`** in
the plugin header — WP 5.8+ core skips wordpress.org updates entirely for a
plugin whose Update URI is non-w.org; this is the primary, version-arithmetic-
independent protection and must stay until the upstream merge. (2)
**Renumber to 2.1.0-beta.1** (Erkki's call) — clears upstream's 2.0.0 for
humans and for any pre-5.8-semantics tooling, makes "which code runs here"
visible at a glance, and the eventual merge-back lands monotonically
(upstream 2.0.0 → rewrite 2.1.0). All version literals moved in one pass
(plugin header, both PHP constants, Stable tag, package.json+lock, test
bootstraps, ConstantsTest, CHANGELOG/README/MIGRATION); CHANGELOG carries a
version-note explaining the renumber.

**Rationale:** the number bump alone is fragile (upstream's next release
re-collides); the header alone leaves the confusing "2.0.0-beta.1 vs
upstream 2.0.0" support story. Together they cover both the machine and the
human failure modes.

**Alternatives:** slug/folder rename (rejected — breaks the 1.x→2.x in-place
upgrade path and all of MIGRATION.md); `site_transient_update_plugins` filter
(rejected — code where a header suffices); leaving 2.0.0-beta.1 + header only
(rejected — support ambiguity, and beta.1 < 2.0.0 reads as "outdated" in
every plugin list).

**Relationships:** UPSTREAM_AUDIT #128 (now also carries the clobber note);
F3-34/P6 (the release-prep that surfaced this); MIGRATION.md §"Class not
found" (version pointer corrected — composer.json never carried a version).

### F3-36 — SkuResolver: synthetic `wc-{id}` product keys for SKU-less stores (pilot find)

> **⚠️ SUPERSEDED by PRO-1224 (2026-07-10).** The "real SKU when set, else
> synthetic `wc-{id}`" rule below is REVERSED: SkuResolver now ALWAYS emits
> `woo-<id>` (the platform id, prefix `woo-` not `wc-`) and NEVER the merchant SKU
> field. The engine's `sku` is a join/identity key, not a human code, and merchant
> SKUs collapse distinct products (PRO-1223). The SkuResolver *pattern* (one
> chokepoint, canonicalization, deleted-line fallback, never-drop) survives intact;
> only the "prefer the merchant SKU" branch and the `wc-` prefix are gone. See the
> PRO-1224 entry at the end of this file. The context below is kept for the history.

**Context:** first day of pilot debugging (2026-06-12). The pilot store has
**no SKUs on any product**, and its older orders reference deleted products.
The engine keys its entire pipeline on `sku` (catalog natural key
`(tenant_id, sku)`; order `items[].sku` required; browse `product_view`/
`cart_*` `sku` required), and the plugin treated "no SKU" as "not ingestable"
on every surface — each failing differently: catalog units were dropped
BEFORE enqueue (silent — zero Event Log trace, engine catalog simply empty),
orders built an empty `items[]` and were D6-rejected (`items: Array must
contain at least 1`) on every retry (the user-visible symptom: 50 failed
order.upserts), and the beacon omitted `sku` so the engine rejected those
browse events. Net effect: the engine could learn nothing from this store.

**Decision:** one shared `Support\SkuResolver` supplies the engine key on all
three surfaces — the real SKU when set, else synthetic **`wc-{product or
variation id}`** (stable, ≤64 chars). CatalogPayloadBuilder/expand no longer
filters SKU-less units (CatalogHookHandler's belt-and-braces guards removed
too); OrderPayloadBuilder keys lines via the resolver (variation id wins,
like catalog treats variations as units); StorefrontBeacon always emits
`sku`. Plus a safety net: an order whose every line drops is a TERMINAL SKIP
in OrderFlusher (third skip case) — never sent, because `items` min-1 can
never pass. The mock orders route now enforces `items` min-1 (it was the
divergence hiding this from integration), and the catalog walk's D6
lock-proof lever moved from empty-sku (now impossible through the builder)
to an over-64-char SKU.

**Deleted products — assumption falsified by the integration env:** the
plan assumed WC line items retain the product id after deletion. WRONG on
current WC: permanent deletion ZEROES the items' `_product_id` (verified
empirically, WC 10.7 wp-env — the new integration test caught it first run).
So all-deleted orders (the pilot's 2219–2227) resolve as unkeyable → empty
items[] → terminal skip: they leave the queue cleanly instead of D6-failing
forever. The id-survives path (older WC / intact data) still keys `wc-{id}`
and ingests — unit-covered. Both outcomes correct, neither send-and-fail.

**Live evidence instead of a new probe:** non-catalog SKUs in orders and
browse are ACCEPTED by the engine — proven by the existing green walks
(walk-3.3-orders sent item SKUs with no catalog row, 12/12; walk-3.4-browse
sent WALK-* SKUs never ingested, 13/13). So synthetic keys are safe even
when their catalog row never existed.

**Trade-off (accepted):** if the merchant later assigns a real SKU to a
product already ingested as `wc-{id}`, the key changes — fresh catalog entry,
history splits between the keys. Accepted over a tenant-level "key mode"
setting (more code; identical output for a fully SKU-less store). Documented
in SkuResolver's docblock.

**Alternatives:** require the pilot to add SKUs (rejected — unrealistic for a
store that never used them, impossible for deleted products); send product_id
in a separate field + engine-side change (rejected — contract change for a
plugin-solvable problem); skip-only fix without synthetic keys (rejected —
fixes the Event Log noise but leaves the engine blind to the whole store).

**Relationships:** F3-16/F3-19 (the builders); F3-18/N-7.1 (D6 split — the
lock-proof lever change); F3-21 (IsoDate — same single-source pattern);
LESSONS §2.10 (the silent-drop lesson). Pilot go-live: after deploy, re-run
catalog backfill + Event Log "Retry all failed" (the flusher rebuilds
payloads fresh at flush, so the failed rows heal in place).

### F3-37 — Abandoned-cart backlog guard + per-cart error handling (pilot mass-email incident)

> **Note (PRO-1195, 2026-07-11):** the legacy email pass this hardened is
> retired; the backlog guard (same filter name, same 24h default, same
> "expire without emailing" semantics) now lives in `CartAbandonmentSweeper`.

**Context:** minutes after the 2.x plugin was installed on the pilot store,
customers received abandoned-cart emails in bulk. **Attribution (corrected
mid-investigation):** the emails came from **CartBounty Pro** — a third-party
abandoned-cart plugin active on the site — NOT from our pipeline. Evidence:
the email links carry `cartbounty-pro` references, and the client's Smaily
account contains no automation and no such email. The first working
hypothesis (our legacy pipeline → Smaily autoresponder) was WRONG and is
recorded here deliberately: it was plausible, code-supported, and falsified
only by looking at the actual email — attribute from the artifact, not the
architecture. The most likely trigger mechanism: the site's WP-Cron had been
effectively dead (which is also why no one remembered abandoned-cart being
on), every cron-based plugin accumulated backlog, and the plugin swap
(deactivating the old 1.6 code / re-arming schedulers) brought cron back to
life — CartBounty's overdue reminders all fired at once. Needs on-site
confirmation (CartBounty's email log timestamps vs install time).

**Why we still fixed OUR pipeline:** it has the IDENTICAL structural flaw,
and the pilot DB has it ENABLED (`smaily_connect_abandoned_cart_status` was
true from the 1.6 era — the wizard toggle honestly displayed it). The legacy
email pass drains `abandoned AND mail_sent IS NULL` with NO time bound, and
aborted the whole loop on the first API error WITHOUT marking anything — so
a dormant period accumulates a backlog, and the first successful tick after
re-arming (e.g. the moment a working autoresponder appears in the Smaily
account) would mass-mail it. On the pilot the flood was one working
autoresponder away.

**Decision (both in `integrations/woocommerce/cron.class.php`):**
1. **Backlog guard:** a reminder is sent only for carts whose `cart_updated`
   is within `smaily_connect_abandoned_cart_max_age_seconds` (filterable,
   default 24h); older carts are expired — marked `mail_sent` without
   emailing, with a summary log line. The age signal is `cart_updated`, NOT
   `cart_abandoned_time`: the status pass stamps the latter NOW() when it
   (re)marks, so after dormancy every historical cart looks freshly
   abandoned by that column. Comparison is epoch-int, not string — MySQL
   reads back `Y-m-d H:i:s` while the writer used the Z-form, and a string
   compare breaks on the separator byte (`' ' < 'T'`) for same-day carts.
2. **Per-cart error handling:** API failures log + `continue` (next cart)
   instead of the upstream `return` (abort loop, mark nothing). A failed
   cart stays unmarked → retried next tick until it sends or ages out of
   the guard window. Bounded retries, no hidden backlog growth.

**Tests:** `AbandonedCartGuardTest` (integration, real table + real hook
wiring; the Smaily client is deliberately unconfigured so any send attempt
fails — a `mail_sent` mark can only come from the guard). Covers: stale
expired even when an earlier cart's send failed (the abort regression);
fresh failed cart stays unmarked; same-day cart not wrongly expired (the
string-compare seam). The tests env never runs activation, so the fixture
creates the cart table via the real Lifecycle DDL (reflection — no schema
duplication).

**Alternatives:** turning the AS job off when the feature is disabled
(insufficient — the pilot has it ENABLED); deleting old cart rows on upgrade
(destructive, loses analytics); time-bounding the SQL query itself (equal
effect but the expiry would be invisible — the guard logs what it expired).

**Relationships:** upstream cherry-pick #123 (same legacy cron file); F3-36
(same day, same lesson family — LESSONS §2.11's silent accumulation, here in
time dimension); STATUS pilot checklist (CartBounty coexistence decision is
the merchant's: two abandoned-cart systems on one store = double-reminder
risk).

### F3-38 — Non-product exclusion is the engine's job (signal, not a connector filter)

**Context:** the catalog-correctness brief (`PLUGIN_BRIEF_catalog_correctness.md`
§P3 / `PROMPT_woo_plugin_team.md` item 6) asked the plugin to filter
non-products (gift cards, donation items, language-switcher pseudo-products) out
of catalog sync — call it "CC.4". CC.1–CC.3 (canonical collapse + `{lang:value}`)
are done and live-walked; this was the last open item.

**Decision (CONFIRMED + implemented, 2026-06-14):** the plugin does **NOT**
build a hard non-product filter. Instead it sends *signal* — `product_type`
(`WC_Product::get_type()`, incl. gift-card plugins' custom types), `is_virtual`,
`is_downloadable` as top-level catalog fields — and the **engine's
`recommendable` flag (migrations 0039/0040) owns the exclusion decision**. Engine
team confirmed the division (commit 37a8f66) and consumes the fields
(`classifyRecommendable`: gift-card types → excluded; virtual/downloadable never
auto-exclude). `CatalogPayloadBuilder` always emits the three fields; builder
unit tests + live-walk 9/9 (`bin/walk-cc3-multilingual.cjs` — the engine accepts
a `pw-gift-card` type). The Q&A + the two engine return-questions
(language-switcher `wc-49143`; MiuMjau's gift-card type string) are in
`docs/ENGINE_TEAM_recommendable_signal.md`. Ship the signal with the canonical
re-backfill so the post-reload catalog classifies first-pass.

**Rationale:** "is this product recommendable?" is a **business-model decision**,
not a structural one a connector can make safely. (1) It's per-client — MiuMjau's
donation is categorised `kassitoit` (same as real food), its gift cards are a
specific plugin's type; the next store looks nothing alike. (2) Any structural
heuristic backfires: a `is_virtual()`/`is_downloadable()`/category filter would
drop the **real products of a legitimate digital-goods store**. (3) The engine
flag is the right layer — centralized, recomputed per upsert, self-healing on
re-sync, tunable without redeploying every connector. Plugin sends signal; engine
decides — not the reverse.

**Alternatives:** a plugin-side filter keyed on the gift-card plugin's product
type (rejected as the default — per-client, needs the merchant's plugin identity,
and only catches gift cards, not the donation case); name/category heuristics
(rejected — fragile, lossy, false-positives). If the engine insists on a
connector-side drop for a specific case, we require a robust per-type rule, never
a name/category guess.

**Relationships:** F3-36 (SkuResolver — the same "engine owns the keying/decision,
plugin supplies clean data" division); engine `recommendable` (migration 0039,
defense-in-depth already shipped); CC.1–CC.3 (the correctness work this closes
out); `ENGINE_TEAM_recommendable_signal.md` (the open question).

### F3-39 — catalog.delete skips objects with empty REQUIRED fields (auto-draft GC burst)

**Context:** the pilot's Event Log showed a burst of failed `catalog.delete` rows
(2026-06-14, all within one second), each `d6_item_error field=category_path` or
`field=product_url: String must contain at least 1 character(s)`. Root cause: the
catalog backfill is `publish`-only (`CatalogBackfillJob`), but `CatalogHookHandler`
fired `catalog.delete` for *any* deleted product post — including the `AUTO-DRAFT`
products WordPress's daily auto-draft GC (`wp_scheduled_auto_draft_delete`) purges
in bulk. Those have an empty `category_path` and were never ingested.

**Decision (implemented, 2026-06-14):** `CatalogHookHandler::enqueue_delete()` skips
a removal whose captured object has a blank `category_path` **or** `product_url`
(`removable()` helper; the multilingual `{lang:value}` empty-array form counts as
blank). The engine has no delete-by-key — removal is an UPSERT with `in_stock=false`
that must pass `ProductSchema` (both fields REQUIRED non-empty, §3) — so such a row
can only ever 400. The skip is silent, mirroring the existing non-product
early-return in `on_delete_product()`.

**Rationale:** a never-published artifact was never sent (backfill is publish-only),
so there is nothing to remove — the `catalog.delete` is a no-op the engine rejects.
The guard is **delete-only by design**: an empty `category_path` on a *published*
product is an intended merchant-data-gap signal the engine surfaces on the upsert
path (`CatalogPayloadBuilder::primary_category_path()` docblock), so a wire-level
guard covering upserts would suppress real signal. See LESSONS §2.12 (the
backfill-vs-incremental eligibility asymmetry) and the §2.11 contrast (this is a
*correct* pre-enqueue drop — the record cannot and should not be sent).

**Alternatives:** (a) a `post_status`-based filter on the delete hook (rejected as
primary — a published-then-trashed product is status `trash` at
`before_delete_post`, needing `_wp_trash_meta_status` archaeology to tell it from a
never-published draft; the required-field check captures the same intent more
directly); (b) a minimal sku-only delete payload (rejected — the engine's
UPSERT-only lifecycle requires the full `ProductSchema` even for removal, §3
"Lifecycle is UPSERT-only"); (c) a wire-level guard in `IngestFlusher::row_to_object`
covering both paths (rejected — would suppress the published-product data-gap
signal, above).

**Relationships:** CC.1–CC.4 / F3-38 (the catalog-correctness work this follows);
F3-36 (LESSONS §2.11 — silent pre-enqueue drops, the contrasting case);
`CatalogBackfillJob` (the publish-only filter the hook now mirrors in spirit).

**ADDENDUM (PRO-1491, 2026-07-21) — the upsert-path non-guard reconfirmed with
253 rows of live evidence; root cause narrowed.** MiuMjau accumulated 253
failed `catalog.upsert` rows, all `d6_item_error field=category_path`
(oldest 2026-06-11, i.e. this has been happening since near go-live).
Investigated whether this was still the right call given the volume — it is;
**no code change to `CatalogPayloadBuilder`**, the "no invented fallback"
decision above stands. What's new: confirmed EMPIRICALLY (not just read from
code) exactly which product shape produces the empty string. WooCommerce's
own `WC_Post_Data::force_default_term()` (hooked on the `set_object_terms`
action) re-asserts the store's `default_product_cat` on ANY
`wp_set_object_terms( …, array(), 'product_cat' )` call that would otherwise
leave a product with zero categories — so "a merchant/tool actively cleared
the category" self-heals to "Uncategorized" (non-empty) and is NOT the
failure mode. The failure mode is a product post whose `product_cat`
relationship was **never established through any `wp_set_object_terms` call
in the first place** — that hook never fires, so there is nothing to heal.
Concretely: a bulk-import/migration tool that writes `wp_posts` + product
meta directly (bypassing `WC_Product::save()`), or a WPML/WCML translation
"stand-in" row created outside WC's normal product-save path. The 253 rows
are real, structurally-missing-category products, exactly the signal F3-39
intended the engine to surface — not a plugin bug. **Mock-divergence closed
in the same pass**: the integration mock had never enforced `category_path`
non-empty (accepted it silently), which is exactly the shape of bug this
repo's mock-vs-live discipline exists to catch (LESSONS §2.3/§2.4) — fixed to
match the live `d6_item_error`. **Recovery for the 253 rows is human, not a
deploy**: `IngestFlusher::row_to_object()` rebuilds catalog.upsert payloads
FRESH from `wc_get_product()` on every send, including a retry (F3-44 holds
here) — so assigning each affected product a real category in wp-admin, then
using the Event Log's "Retry", is sufficient; no re-backfill needed.

**REVISION (PRO-1491, 2026-07-21, approved by Erkki) — the "no invented
fallback" call above is narrowed for the empirically-confirmed case; two
fixes shipped.** The ADDENDUM's live evidence (253 real, PUBLISHED MiuMjau
products silently excluded from the engine catalog) changed the cost-benefit:
these are not abandoned artifacts, they are products a real merchant expects
recommended. **Fix A** — `CatalogPayloadBuilder::primary_category_path()` now
falls back, when a published product has zero `product_cat` terms, to the
**store's own `default_product_cat` option**, resolving that term and using
its actual NAME at build time (never a hardcoded English literal — a
localized/renamed store gets its own term name). This is WooCommerce's own
"uncategorized" semantics, not an invented bucket — the connector still makes
no business-model call, it forwards a value the store itself already
designates. If even the default term is unresolvable (a genuinely broken
store: the option or the term itself is gone), the method still returns `""`
and the engine's REQUIRED-field rejection still fires — the original
fail-loud behavior is preserved for that edge, not removed. **Fix B**
(orthogonal root-cause overlap found during the same investigation) —
`CatalogHookHandler::on_save_product()` now skips `auto-draft` status posts
(mirroring its existing `trash` early-return): opening the WordPress "Add
product" screen creates an auto-draft placeholder (no name, no category, no
price) that fires `save_post` before the merchant has entered anything: this
was a second, independent source of empty-`category_path` catalog rows, one
that Fix A cannot fully help either (the recovery is the row never having been
enqueued, not a fallback name). Plain `draft` status was left unchanged
(out of scope — a merchant explicitly saving a draft is a real action, and
Fix A now covers a draft's possibly-still-empty category too). The mock's
strict empty-`category_path` rejection (the ADDENDUM's mock-divergence fix)
is UNCHANGED and still correct — it now guards the narrower fail-loud edge
Fix A's fallback doesn't reach.

### F3-40 — Trashed products stay in the catalog as `in_stock=false` (pilot orphan-join fix)

**Context:** an engine-team data audit (2026-06-17) found ~4% of pilot order lines
had no matching `catalog.sku` (~567 rows, ~265 customers) → those customers' species
can't be inferred from purchases → they get a balanced (wrong) mix. Erkki traced a
chunk of the missing product ids to the WordPress **trash** (not permanent deletion).
Mechanism: the catalog backfill is `publish`-only, and **trashing fires no catalog
hook** — `before_delete_post` is permanent-delete-only, and trashing routes through
`wp_update_post`, not a delete. So a trashed-but-once-bought product is neither
re-sent nor removed; its engine catalog row goes missing (if it was trashed before
the first ingest) or stale. The engine never deletes-by-absence (contract §3), so the
fix is to *keep* the row, marked unavailable — not to drop it.

**Decision (implemented, 2026-06-17):** a trashed product is kept in the engine
catalog as an `in_stock=false` UPSERT (the engine has no delete-by-key, so a
`catalog.delete` row IS an `in_stock=false` upsert — `IngestFlusher::row_to_object`
stamps it), so its order-history join / model training survives but it can't be
recommended. Two paths, both reusing the existing removal machinery:
- **Live (A+B / hooks):** `Bootstrap` binds `wp_trash_post → on_delete_product`
  (in_stock=false) and `untrashed_post → on_save_product` (re-sync real stock).
- **Backfill (A):** `CatalogBackfillJob` enumerates `publish` **and** `trash`; a
  published post upserts (flusher loads fresh), a trashed post enqueues a
  `catalog.delete` carrying its captured object, guarded by the now-shared
  `CatalogHookHandler::is_removable` (a blank `category_path`/`product_url` removal
  the engine would 400 is skipped, F3-39).

**The clobber guard (the subtle part):** `wp_trash_post()` calls `wp_update_post()`
to set status `trash`, which fires `save_post_product` → `on_save_product` AFTER the
removal was enqueued — re-upserting the product as `in_stock=true` and undoing the
removal. `on_save_product` now early-returns when the saved post's status is `trash`
(the trash/delete path owns a trashed post). Untrash restores the status *first*,
then fires `untrashed_post`, so a restored product is correctly NOT skipped. This was
caught by the new live-hook integration test, not in review — the two hooks firing on
one trash is non-obvious.

**Scope / known limits:** this closes the **trash** gap, not the hard-delete one — a
*permanently* deleted product's data is gone from WC, so no catalog row can be built
(the engine must tolerate such order lines; it already does, it just can't infer from
them). *(The hard-delete gap is closed later by PRO-1230: contract v1.3.0 §3b
`catalog/remove` needs only the parent product id, capturable at before_delete_post —
see the PRO-1230 entry.)* Multilingual: the collapse keys on a *published* canonical only, so a published
translation of a trashed default-language product is kept (stands in as in_stock=true);
a fully-trashed multilingual product may emit a harmless duplicate `in_stock=false`
per language (idempotent on the engine's SKU upsert). After deploy the pilot needs a
**catalog re-backfill** so existing trashed products enter the graph.

**Alternatives:** (a) include all non-publish statuses (draft/private) — rejected,
drafts are not real sales and would add noise; trash is the precise, merchant-driven
"discontinued" signal. (b) a wire-level `in_stock=false` stamp in the flusher for any
non-publish row — rejected, the `catalog.delete` event already carries that semantics
cleanly. (c) guard `on_save_product` on the canonical product's status instead of the
saved post's — rejected, it drops a published translation whose canonical is trashed.

**Relationships:** F3-39 (the `is_removable` guard this shares + extends to the
backfill); F3-36 / LESSONS §2.11 (never a silent drop — a trashed product is kept,
not dropped); the engine-team 2026-06-17 brief (Teema 2, the orphan-join evidence).

### F3-41 — Browse beacon renamed off "beacon" to dodge ad-block filter lists

**Context:** the engine saw zero real browse events from the pilot (Teema 3). After
the two-gate config was fixed (track-browsing toggle on + CookieYes marketing
consent), Erkki found the storefront request only succeeded with the **ad-blocker
off** — with it on the beacon POST was blocked (surfaced as a 404/failed request).
The cause is the literal word **"beacon"**, which is on EasyPrivacy (and similar)
ad-block filter lists. It appeared in two browser-visible places: the script URL
`dist/public/js/beacon.js` and the proxy route `/wp-json/smaily-connect/v1/beacon`.
Both were blocked by name.

**Decision (implemented, 2026-06-17):** rename the two browser-facing names to neutral
strings — the shipped script is **`dist/public/js/sc-runtime.js`** (vite entry key
`public/js/sc-runtime`, source file `public/js/beacon.ts` unchanged) and the proxy
route is **`/relay`** (`BeaconEndpoint::ROUTE`, `StorefrontBeacon` `beaconUrl`, the
`EndpointRegistry` listing, the browse live-walk, and the integration tests). The
script HANDLE became `smaily-connect-runtime`. **Internal names are deliberately NOT
touched** — the PHP classes (`StorefrontBeacon`, `BeaconEndpoint`), the JS source
files (`beacon.ts`/`beacon-core.ts`), the `window.smailyConnectBeacon` boot global,
and the `beaconUrl` config key all keep their names: they are not browser-visible as
URLs/requests, so renaming them is churn with no ad-block benefit.

**Consent is unchanged — this is not consent evasion.** The beacon stays first-party
(the merchant's own store + recommendation engine) and fully consent-gated (the admin
track-browsing toggle + the WP Consent API `marketing` category). The rename only
removes a filename/path that a *blunt, name-based* filter rule false-flagged; it does
not bypass the user's consent choice, fire without consent, or hide what the beacon
does.

**Scope / limits:** no rename is bulletproof against every list — some aggressive
rules block `/wp-json/` analytics-ish patterns broadly — but it clears the specific
EasyPrivacy match the pilot hit. Confirming the storefront POST now returns 200 with
a blocker enabled is a **manual browser check** (not automatable; the integration
test only proves the server side dispatches `/relay`). The engine contract is
untouched — `/api/v1/ingest/browse` is engine-side; only the plugin's internal WP
proxy name changed.

**Alternatives:** (a) route the POST through `admin-ajax.php` (core, rarely blocked)
— rejected, the tracker-ish `action` param can still be matched and it abandons the
clean REST namespace; (b) inline the script to avoid an external `.js` request —
rejected, larger change, and the POST URL would still need renaming; (c) leave it and
document the ad-blocker caveat — rejected, browse is the cold-start amplifier the
pilot needs working.

**Relationships:** 3.4 browse-beacon (the surface this renames); the engine-team
2026-06-17 brief (Teema 3, the zero-events evidence); CLAUDE.md "Browse" note.

### F3-42 — Order status mapping: custom statuses default THROUGH as a sale; on-hold is not a sale

**Context:** the engine-team 2026-06-19 brief (order #58922 + the earlier Teema 1)
found orders in WooCommerce **custom statuses** never reached the engine. The plugin
mapped WC status → engine enum with a 5-key **allowlist** (`completed`, `processing`,
`on-hold`, `cancelled`, `refunded`); any status absent from it — including every
merchant/shipping-plugin custom status like `label-printed` / `shipped` / `pakikaart-
prinditud` — mapped to `''` and was **silently dropped** (the live hook skipped it AND
the backfill's `status IN (allowlist)` filter excluded it). The engine accepts only the
strict enum `completed|processing|cancelled|refunded`, so a raw custom status can't pass
through verbatim.

**Decision (implemented, 2026-06-19, reverses part of F3-22):** invert the model to a
**denylist**. `map_status()` returns `''` ONLY for an explicit non-sale set
(`pending`, `on-hold`, `failed`, `checkout-draft`, `draft`, `auto-draft`, `trash`);
everything else maps to a sale — the explicit `STATUS_MAP` entries
(`completed`/`processing`/`cancelled`/`refunded`) keep their target, and **any other
(custom/unknown) status defaults to `processing`** (`DEFAULT_SALE_STATUS`). Conservative
default: a confirmed purchase *in progress*, not `completed`, so a custom fulfilment
state isn't over-claimed as finished; if the order later truly completes, the live hook
re-sends it as `completed`. The order backfill's single-source filter flips to
`OrderPayloadBuilder::non_sale_wc_statuses()` and `status NOT IN (denylist)` (CC-9 — the
hook and backfill still can't drift); the flusher's `map_status===''` skip is the safety
net for any non-sale status the SQL prefixing doesn't catch.

**on-hold reversal:** F3-22 mapped `on-hold → processing` ("purchase intent"). The engine
team's brief states on-hold is **not yet a sale** ("pole veel müük" — payment not
captured). Decision (Erkki, 2026-06-19): **follow the engine — on-hold is non-sale**
(moved into the denylist). Safe: an on-hold order that gets paid transitions to
processing/completed and is sent then; one that never pays is correctly never sent.

**Residual risk (accepted):** a FUTURE merchant with a custom **non-sale** status (e.g.
`quote-requested`, `fraud-review`) would have those sent as a sale. MiuMjau's custom
statuses are fulfilment (label-printed/shipped = real paid sales), so it's safe for the
pilot; a small `apply_filters` on the map can tune it later if a real client needs it
(not built now — no added complexity for the pilot).

**Alternatives:** (a) a per-merchant filterable status map — rejected for now as added
complexity the pilot doesn't need (the denylist default covers MiuMjau); (b) keep the
allowlist and tell the merchant to use only standard statuses — that was the prior
"client fixes WC-side" punt (Teema 1), and it kept biting (#58922) because merchants use
custom shipping statuses as terminal states.

**Relationships:** F3-22 (the status mapping this reverses for on-hold + customs); the
engine-team 2026-06-19 brief; CC-9 (the hook↔backfill single-source rule preserved);
F3-43 (the same brief's deleted-product fix, below).

### F3-43 — A deleted-product order line is kept (never dropped) so the order is never lost

**Context:** same 2026-06-19 brief, the #58922 symptom. A guest order with a **deleted**
product was marked **"sent"** by the plugin but **never reached the engine** (no POST at
all). Root cause: `OrderPayloadBuilder::items()` keyed each line via `SkuResolver`; for a
deleted product whose stored ids current WC **zeroes** on permanent deletion,
`resolve_order_item()` returned `''` and the line was **dropped**. Every line dropping →
empty `items[]` → `OrderFlusher::row_to_object` returned `null` → `AbstractD6Flusher`
**`mark_sent()`** the row WITHOUT POSTing (the F3-36 "terminal skip"). So the order
silently vanished AND showed "sent". This is the F3-36 design working as written — but a
"clean skip" is still **silent loss** of the order's RFM/tier value.

**Decision (implemented, 2026-06-19, reverses F3-36 for the deleted-line case):** a
product line is **NEVER dropped**. `SkuResolver::resolve_order_item()` no longer returns
`''`: when both stored ids are zeroed it keys the line on the **order-item id**
(`wc-oi-{item_id}`) — guaranteed non-empty and unique (chosen over the brief's literal
`wc-{product_id}`=`wc-0`, which would collide across deleted lines). The qty / unit_price
/ line_total already come from the line-item **snapshot**, which survives product
deletion, so the line is fully serialisable. The `wc-oi-…` key won't match a catalog row
(no item-level species inference), but the order **ingests** (RFM / tier) — the accepted
trade-off; the order surviving is what matters. `items()` drops its `sku===''` guard.

**Scope:** this closes the *deleted-product line* gap. The flusher's empty-items
terminal-skip REMAINS, but now only fires for a genuinely **product-less** order (only
shipping/fee lines) — the only remaining empty-`items[]` case. Permanently-deleted
products still have no catalog row (F3-36); the order line keys synthetically regardless.

**Silent-"sent" note:** the engine's D6 `errors[]` → `mark_failed` path (F3-18 /
AbstractD6Flusher) was already correct — #58922 never hit it because the terminal skip
`mark_sent` before any POST. Fixing this means the order now POSTs; a genuine engine
rejection is then marked FAILED + retryable, as designed. (Storing the real request
payload + response in the Event Log — the brief's Problem 3 — is a separate follow-up.)

**Relationships:** F3-36 (SkuResolver + the terminal-skip this reverses for deleted
lines); F3-18 (the D6 errors path that already marks FAILED); the engine-team 2026-06-19
brief (#58922); LESSONS §2.11 (never a silent drop — now upheld for orders too).

### F3-44 — Event Log stores the real request payload + engine response per row

**Context:** the engine-team 2026-06-19 brief, Problem 3. The Event Log "Details"
panel showed `Payload: []` for every row — order/catalog rows enqueue an EMPTY
payload (the flusher builds the wire object fresh at send, F3-8), so the stored
`payload` column is empty, and only a short `last_error` code is kept. So a merchant
(or we) couldn't answer "what did we actually send, and what did the engine reply?"
— and the #58922 terminal-skip read a bare "sent" with no trace it never POSTed.

**Decision (implemented, 2026-06-19):** capture the send-time exchange per row, on
BOTH durable queues, and surface it in Details.
- **Schema (migration 007):** two nullable `LONGTEXT` columns on
  `smly_rec_event_queue` AND `smly_plus_event_queue` — `sent_payload` (the exact
  JSON POSTed; null when nothing was sent) and `last_response` (a small JSON
  summary `{http, outcome, error?}`). A new `IngestQueue::store_exchange` /
  `EventQueue::store_exchange` writes them (separate from `mark_*()` so those
  signatures — and the test doubles overriding them — stay unchanged).
- **Rec-engine capture (clean):** `AbstractD6Flusher` is the single choke point —
  after `send($objects)` each row has its sent object + the batch response. It
  stores `accepted` / `rejected{error}` / `http_error` per row, and on a
  terminal-skip stores `sent_payload=null, last_response={outcome:"skipped"}` (the
  visibility fix for the silent "sent").
- **Smaily capture:** the legacy queue is dispatch-based, so the `Smaily\Client`
  records its `last_exchange()` in the single `request()` chokepoint and the
  `Flusher` reads it (via `try/finally`, so a throwing call is still captured) and
  stores it; a no-call event records a skip marker.
- **NEVER stores the Authorization header** — only method/endpoint/body + reply.
- **All rows, success included** (the brief wants "what landed", not just failures),
  each field **trimmed to ~10 KB**; pruned with the row by `QueueJanitor` (sent 30 d
  / failed 90 d), so the table stays bounded.
- **Details UI:** the modal shows **"Request sent to the engine"** + **"Engine
  response"** (pretty-printed) above the enqueued payload; `/events/detail` returns
  the two fields ('' for pre-migration rows / not-yet-flushed rows).

**PII note:** `sent_payload` carries the same data already in WC (email / order
fields); it's transient + janitor-pruned — no new GDPR surface beyond the queue.

**Already solved, NOT rebuilt:** the per-item `errors[]` → `mark_failed` split
(F3-18) was correct; this adds the *visibility* layer on top, it doesn't change the
state machine.

**Relationships:** F3-8 (why order/catalog rows enqueue empty — the build-fresh
decision this works around); F3-18 (the D6 errors path); 3.10.0 (the Event Log this
extends); the engine-team 2026-06-19 brief (Problem 3); F3-43 (the silent-"sent"
the skip marker now exposes).

### F3-45 — Legacy admin settings-PAGE removed; widget + Settings link relocated (Faas 2)

**Context:** the legacy `Smaily_Connect\Admin` settings page is redundant — all
configuration now happens in the new wizard/Settings UI. Removing the legacy view
layer (Faas 2; Faas 1 was the F1 Settings-link repoint). **Hard constraint (Erkki):
must not break the ~2000 existing installs** — only remove what's unneeded under the
new plugin or trivially replaceable with 100% functionality preserved.

**Audit (per-file, before any deletion) — the legacy `Admin` class was NOT pure views:**
- It also registered two LIVE behaviors: the **subscription widget** (a classic
  `WP_Widget` merchants may have placed) and the **Plugins-page "Settings" link**
  (F1 repointed it to the new UI). Deleting `Admin` would silently drop both.
- Its `validate_api_credentials_after_save` hook **early-exits on `REST_REQUEST`**, so
  it did nothing for the new UI's saves — it only served the legacy form. Dead with the page.
- `Notices` + `Notice_Registry` are **NOT dead**: `migrations/upgrade-1-3-0.php` calls
  `Notice_Registry::add_notice()` for the CF7 upgrade notice. Removing them would break
  the 1.3.0 upgrade path. **Kept.**
- **No config is stranded:** the new `SettingsEndpoint` reads AND writes the SAME legacy
  option keys the old page used (`smaily_connect_api_credentials`, `_subscriber_sync_*`,
  `_abandoned_cart_*`), and `EnvDetector` feeds them to the new wizard. The kept legacy
  integrations (`Subscriber_Synchronization` / `Cron` / `Cart`) keep reading those keys —
  now written by the new UI.

**Decision (implemented, 2026-06-19):**
- **Removed** (redundant + non-navigable view layer): `admin/smaily-admin.class.php`,
  `smaily-admin-{settings,renderer,sanitizer}.class.php`, the `smaily-admin-*.php`
  settings partials, `admin/css|js/smaily-admin.*`, the dead `validate_api_credentials`
  hook, and the now-moot hide-legacy-menu shim (2.H.3 — nothing left to hide).
- **Relocated** (so merchants lose nothing) into `smaily.class.php::init_classes`: the
  `widgets_init` → `register_widget(new Widget(...))` and the `plugin_action_links`
  Settings link (→ `admin.php?page=smaily-connect-settings`).
- **Kept** (live dependents): `Notices` + `Notice_Registry` + `partials/notices/*` +
  `smaily-admin-notice.php` (1.3.0 upgrade notice), `Widget`, the WooCommerce integration
  classes, `Cypher`, `Options`, `Rss`, `Cart`, CF7 / Elementor.
- Fixed a stale `@param Admin` docblock in `smaily-api.class.php` (the only other `Admin`
  reference — a doc-only leftover; the constructor never took an Admin).

**Safety:** every removed piece is either unreachable under the new plugin (the legacy
menu is hidden + the Settings link points at the new UI) or redundant (new UI owns the
same option keys). The two live behaviors are preserved verbatim. Gates: ci:strict
exit=0; integration OK 114 (the plugin boots with the legacy admin gone).

**Relationships:** F1 (the Settings-link repoint this completes); 2.H.3 (legacy-menu
hide, now removed as moot); the coexistence model (`setup_completed`) — untouched.

### F3-46 — Server-side landing capture of rec attribution (decoupled from the browse beacon)

**Context:** the engine brief `PLUGIN_BRIEF_woo_rec_link_redirect.md` (rev 2,
2026-06-26): production shows **374 orders / 30 days, 0 carry `smaily_rec_id`** — rec
attribution is empty. The rec link (built by engine `lib/sync/url-builder.ts`) lands on
the merchant's own product page carrying `utm_source=smaily`, `utm_content=<rec_id>`,
**and** the engine's own `smaily_rec` / `smaily_vt` / `smaily_ctx` params. The
capture→stamp→send chain ALREADY existed end-to-end: `HookHandler::
save_attribution_cookies_to_order()` reads cookie `smaily_rec_id` → order meta
`_smaily_rec_id`, and `OrderPayloadBuilder` forwards it. The ONLY missing piece was the
**producer** of that cookie: the sole capture path was client-side JS
(`StorefrontBeacon` → `beacon-core.ts captureUrlParams`), which only runs when
browse-tracking is enabled AND marketing consent is granted AND `sc-runtime.js` isn't
ad-blocked — so on the pilot it never fired and the `rec_id` fell on the floor.

**Decision (implemented, 2026-06-26):** add `Integrations\WooCommerce\LandingCapture`
on `template_redirect` — a server-side producer of the SAME cookies the checkout
stamping already consumes. Zero downstream change (no HookHandler / OrderPayloadBuilder
edit). Specifics:
- **Source param — follow the contract, not the brief literally (Erkki).** The brief
  proposes capturing `utm_content` into new `smre_rec`/`smre_vid` cookies (90d/365d). The
  byte-synced contract (§"Cookie names") instead sources `smaily_rec` → `smaily_rec_id`
  (30d) / `smaily_vt` → `smaily_rec_uid` (365d) / `smaily_ctx` → `smaily_rec_ctx` (30d),
  and even states *"the engine does NOT use `utm_content` for `rec_id`"* (which the engine
  CODE contradicts). We capture **`smaily_rec` primarily** and accept **`utm_content` only
  as a fallback guarded by `utm_source=smaily` + a strict uuid shape** (utm_content is a
  shared GA/ads param). Cookie names + TTLs come from the stored engine config (same
  source `StorefrontBeacon` reads), so the server-set and JS-set cookies are identical.
  **Feedback sent to the engine team** to realign their brief + the §162 contract note
  with `url-builder.ts`. **RESOLVED 2026-06-26:** the engine published brief **rev 3**
  (`re` commit `98b472d`) aligned byte-for-byte to the contract (cookie/param table now
  `smaily_rec`→`smaily_rec_id`/`smaily_vt`→`smaily_rec_uid`/`smaily_ctx`→`smaily_rec_ctx`,
  `utm_source=smaily` guard), and **`utm_content=rec_id` was removed engine-side**
  (`url-builder.ts`: rec_id travels only in `smaily_rec`). Our shipped capture matches it
  exactly; the `utm_content` fallback is now dormant (guarded, harmless — kept as a
  forward-compat safety net). Contract md5 unchanged (the brief moved to the contract).
  Engine also shipped a **domain-less `rec_N_link_path`** (`re`
  `DECISION_rec_link_domainless.md`): the merchant template carries the literal domain +
  `{{rec_N_link_path}}`, so the `smaily_*` params STILL land on the shop on every click —
  **no plugin change** (our capture works across both the old `rec_N_link_url` and new
  `rec_N_link_path` template forms). That decision names the **shop-side plugin path as the
  preferred-accuracy attribution route** (a baked `smaily_rec` is immune to the nightly
  recommendation re-sync that can shift a slot→rec reconstruction).
- **Consent — captured UNCONDITIONALLY when connected (Erkki).** Recommendation
  attribution is a first-party functional signal (a rec_id uuid + an opaque visitor
  token, not PII on their own); tying engine recommendations to real purchases is the
  whole point, and it must not depend on the browse-beacon's toggle/consent/ad-block
  path. **Browse telemetry (Layer 2) stays separately gated** behind the browse-tracking
  toggle + marketing consent (`StorefrontBeacon` unchanged). Escape-hatch: the
  `smaily_connect_capture_attribution` filter (default true) disables it.
- **Gates:** `is_connected()` (rec links exist only for a connected tenant; orders ingest
  only then) + trigger-param presence (fast bail on every ordinary request) +
  `headers_sent()` guard (a `headers_already_sent()` seam so tests can exercise the write
  path past PHPUnit's own output). Cookie attributes mirror the contract: `Path=/`,
  Domain = `COOKIE_DOMAIN`, `SameSite=Lax`, `Secure` on https, `HttpOnly=false` (the
  beacon proxy reads them client-side). `$_COOKIE` is kept coherent within the request.
- **Scope:** Layer 1 only. NOT built: the brief's optional redirect endpoint (§3.4,
  YAGNI), the Layer-2 site-wide `smre_vid` generation, and a fix for the pre-existing
  block-checkout (`woocommerce_store_api_checkout_order_processed`) stamping gap (classic
  checkout only, as today). **UPDATE 2026-06-30 (`e55514d`): the block-checkout stamping gap
  is now FIXED** — it was THE cause of the MiuMjau `smaily_rec_id`-empty regression (MiuMjau
  runs block checkout, so the cookie was captured but never stamped onto the order;
  `is_connected=true`, `order.upsert` 200, but no attribution fields). `HookHandler::
  on_block_checkout_order_processed` (bound to `woocommerce_store_api_checkout_order_processed`)
  now stamps the same cookies on block orders. Past orders can't be plugin-backfilled (the
  checkout cookie is gone) — retroactive attribution is engine-side via the action-log (click
  `value` carries `smaily_rec`, match to order by email+time, ~30-day window). Full write-up:
  `docs/RESPONSE_smaily_rec_capture_regression.md`.

**Gates:** ci:strict exit=0 (unit 391 +17, JS 158, PHPStan clean, PHPCS 0 errors);
integration OK 119 (+5). **Browser-moment verification (does the cookie actually set on a
real rec-link landing, and does a test purchase carry `smaily_rec_id`) is a manual pilot
check** — like the browse render-timing, the server-side path is unit+integration-proven
but the real click→land→buy→attribute round-trip is pilot-verified.

**Relationships:** F3-41 (`StorefrontBeacon` / the JS `captureUrlParams` this complements
server-side); the contract §"Cookie names"; F3-42/F3-43 (the order ingest this feeds);
`PLUGIN_BRIEF_order_sync_reliability.md` (the order-side receiver).

**Addendum — PRO-1679 (2026-08-04): the first-order automation needed the same twin.**
The Store-API callback this section added carried attribution ONLY; PRO-1518 later
carried order-confirmation across on the same hook — but the first-order automation
trigger was left on `woocommerce_checkout_order_processed` alone, so on a block-checkout
store (the WooCommerce default) it never fired for anyone. Fixed by extracting the
enqueue into a shared private `HookHandler::maybe_enqueue_first_order( \WC_Order )` that
both `on_checkout_order_processed()` and `on_block_checkout_order_processed()` call —
same gate, same `smly_plus_first_order_enabled` toggle, same `is_first_order()`
registered-customer-only rule, so guests and second orders behave exactly as before.
**Contact sync is deliberately NOT repeated on the block path**: block checkout syncs the
contact from `on_checkout_block_optin`
(`woocommerce_store_api_checkout_update_order_from_request`), the only place the
Store-API opt-in flag is readable — folding it in here would be a second producer of the
same `contact.sync` row. **No new double-fire guard was needed** (the PRO-1518 shape):
both hooks fire in the SAME request, so the existing per-request `maybe_enqueue()` dedupe
on `automation.first_order:{order_id}` already caps a store where both run at one row.
Out of scope and untouched: guests ever receiving first-order, miscounted first orders,
and consent (automation triggers run on the legitimate-interest basis — settled).

### F3-47 — Contact-sync language goes through `ContactLanguageResolver` (Prike `en`-leak)

**Context:** a managed (non-pilot) client store (Prike) runs the upstream Smaily WP plugin
+ two Make automations as a belt-and-braces contact sync. ~1000 contacts drifted to
language `en`. Root cause: the upstream plugin's "Daily Automatic Subscriber
Synchronization" cron (`Cron::smaily_sync_subscribers` → `Data_Handler::get_user_data` →
`Helper::get_user_language_code`) falls back, for any subscriber lacking a stored
per-user language meta, to `get_current_language_code()` — which the helper's OWN docblock
flags as **cron-unsafe**: in a cron/Action-Scheduler request it returns `get_locale()` =
the **WP site locale**. Prike's WP locale is `en` while its real content default (WPML
`wpml_default_language`) is `et`, so the daily cron mass-pushed `en` and re-clobbered it
every tick, beating the Make automations (whose language logic was actually **correct** —
they read `_user_preferred_language` user meta and `wpml_language` order meta, default
`et`). Our own new live-sync path had a sibling latent bug: `HookHandler::
detect_language_for_user/order` used `get_user_locale()` (admin-UI locale; defaults to the
site locale for front-end customers; emits full `en_US`), and `build_contact_payload`
ALWAYS set the `language` key (empty would wipe the Smaily value).

**Decision (implemented, SP-A 2026-06-30):** one shared `Support\ContactLanguageResolver`
(CC-1) is the single source of the Smaily `language` code for both live-sync and (next
sub-PRs) the backfill / daily refresh. It mirrors the Make automations' (correct) sources
and is **context-independent by construction** (no `ICL_LANGUAGE_CODE` /
`pll_current_language` reads — same answer in a cron tick as an HTTP request):
- `for_user`: `_user_preferred_language` user meta → most-recent order's `wpml_language`
  (injectable provider; `wc_get_orders` limit 1) → the multilingual plugin's configured
  default via `DetectorFactory` (WPML `wpml_default_language` = `et`) → site-locale short
  code. The latest-order tier makes the resolver robust **without a data check on the live
  store** (we can only ship the plugin, not inspect their DB): the non-`et` minority who
  ordered in their language is preserved, not flattened to the `et` default.
- `for_order`: order `wpml_language` → the registered customer's `_user_preferred_language`
  → default → site locale.
- **Normalise to the short code** (`en_US` → `en`); Smaily + Make both key on `et`/`en`.
- **Omit on empty** — resolver returns `''` and the caller drops the `language` key
  (absent leaves Smaily's value intact; empty wipes — never wipe). `HookHandler::
  build_contact_payload/build_automation_payload` + the first-order payload now add
  `language` only when non-empty.
- Filterable: `smaily_connect_contact_language` (final override) +
  `smaily_connect_user_language_meta_key` (redirect the user-meta lookup).
- **Active-language clamp (Erkki):** a resolved code that is NOT one of the site's
  currently-active languages (`DetectorFactory::get_detected_languages()`) is
  clamped to the configured default. The resolver never *invents* a language —
  it only echoes WPML's own values — but this hard-guarantees that dirty history
  (a stray `ru` `_user_preferred_language` / old order `wpml_language` from a
  language the store has since removed) can't spawn a contact list that shouldn't
  exist. No-op when the detector can't enumerate (empty allowlist); the filter
  runs AFTER the clamp, so an explicit override is still the last word.
- **`get_user_locale()` is deliberately NOT a source** — it reintroduces the very
  site-locale leak this fixes.

**Decisions taken (Erkki, 2026-06-30):** (1) keep syncing **all registered customers
regardless of consent** — the new path already does (no opt-in gate); (2) **guests are not
synced** (no account → no contact) — already true; (3) **never send `is_unsubscribed`** —
Smaily owns consent; the new path already omits it (the LEGACY live path sent
`is_unsubscribed=0`, resetting opt-out — a second reason to migrate Prike off it). The
goal is to retire the Make data-sync and let our Connect plugin own the correct contact
sync (contact sync is gated by the email wizard `setup_completed`, independent of the
rec-engine — so it can ship to Prike before they go on the engine).

**Gates:** ci:strict exit=0 (PHPUnit 404 +13, JS 158, PHPStan clean, PHPCS 0 errors);
integration OK 119. The round-trip on the real store (does the corrective backfill move
the ~1000 from `en` to their true language) is a **manual post-deploy check** — we ship
the plugin, the merchant runs it; the resolver is unit-proven.

**Scope:** SP-A (resolver + HookHandler wiring) + SP-B (DONE) — `BackfillJob::
build_subscriber_payload` now adds `language` via the SAME resolver (omit-on-empty), so a
one-off backfill run IS the corrective mass re-sync: every existing contact is re-sent with
the resolver's language instead of the stale `en` the cron pushed. Pending sub-PRs: SP-D
(replace the legacy daily-cron bridge `on_contact_sync_tick` with a correct refresh so the
`en`-clobber stops — until then the backfill fixes contacts but the daily cron can re-drift
them), SP-E (regression test locking `is_unsubscribed` out of the payload), SP-G (cutover:
Connect plugin → wizard → Make data-sync off). Note: at the unit level the backfill resolves
through the single-language SiteLocale detector, so the active-language clamp collapses any
non-site code to the default — the multi-language meta/order priority is proven in
`ContactLanguageResolverTest` (mock detector), not re-tested at the backfill seam.

**Relationships:** CC-1 (single-source-of-truth — joins `SubscriberPayloadBuilder` /
`SkuResolver` / `IsoDate`); the legacy `Helper::get_current_language_code` cron-unsafe
docblock (the bug it routes around); the coexistence map (`setup_completed` email-wizard
gate that owns this path vs the rec-engine `is_connected()` gate).

### F3-48 — Contact-sync mode engine: named presets keyed to lawful basis (DESIGN — approved 2026-06-30)

**Context:** managed/pilot stores want different contact-sync behaviour driven by their
lawful basis for marketing and store shape. Three real cases: Prike wants **all** customers
under *legitimate interest* (legacy couldn't — its cron only synced `user_newsletter=1`,
which is the missing-contacts root cause Make papered over); Client 2 wants **consent**
(opt-in only) with Smaily↔WP reconciliation; Client 3 (MiuMjau-shaped) wants **checkout
opt-in only**, guests, send-only. The F3-47 language fix is mode-independent and already
shipped; the *audience + sync-back + automation-consent* posture is where stores diverge.

**Decision (Erkki, 2026-06-30):** a contact-sync **mode engine** built as a few **named
presets** (not a combinatorial toggle matrix — presets prevent incoherent/unlawful combos
and map to how merchants think). Three presets, internally factored as
`legal_basis` (legitimate_interest | consent) + the `include_guests` sub-option:
1. **All customers (legitimate interest)** — all registered customers, never send
   `is_unsubscribed`, no sync-back, automation `force_opt_in=false` by default
   (an explicit unsubscribe is honoured even here — GDPR Art. 21) + an advanced
   preset-1-only "Force opt-in on automation triggers" toggle to override.
2. **Subscribers only (consent)** — DEFAULT — only `user_newsletter=1`; opt-in → subscribe,
   WP opt-out → `is_unsubscribed=1`; daily Smaily↔WP reconcile **both** directions
   (leavers + returners); automation `force_opt_in=false`.
3. **Checkout opt-in only** — no accounts, checkbox-only (guests), send-only,
   `force_opt_in=false`.

**Rationale / key points:** (a) **Default = consent** is both lawful-safe AND back-compat
(matches legacy's `user_newsletter=1` filter — upgrading never silently broadens audience).
(b) `include_guests` is a checkbox, default OFF (Erkki). (c) Reconcile is **bidirectional**
(Erkki). (d) **Automation `force_opt_in` is mode-driven** — the contact-sync posture also
governs whether a welcome/first_order/abandoned_cart trigger re-subscribes; this unifies a
current inconsistency (new `AutomationRouter` always `true`, legacy abandoned-cart passes
`false`). `force_opt_in` is an undocumented Smaily param — being added to `../re/docs`.
(e) UI = named radio-card presets in `Step2Subscribers` + a `Banner tone="warning"` for the
legitimate-interest preset, matching the existing `Card`/`Toggle`/`Banner` style.
(f) Architecture is CC-1: one `ContactSyncMode` policy core consumed by `HookHandler`,
`BackfillJob`, the SP-D `on_contact_sync_tick` cron takeover, and `AutomationRouter`. The
old unsubscribe-pull is NOT dead — it becomes preset 2's `ContactReconciler`.

**Scope boundary:** does NOT touch rec-engine customer ingest (`CustomerHookHandler`, a
different destination gated by `is_connected()`); abandoned-cart keeps its own enable, only
its automation `force_opt_in` is mode-driven.

**Status:** DESIGN approved (Erkki, 2026-06-30) — full design in `docs/CONTACT_SYNC_MODES.md`;
both open questions resolved (preset-1 `force_opt_in` defaults `false` + advanced toggle;
preset labels kept). **Implementation started: F3-48.1 DONE** — `ContactSyncMode` (preset →
policy) + `ContactAudience` (mode-aware "is this a contact?"), wired into the `HookHandler`
live `contact.sync` gate + the `BackfillJob` audience filter. Default `consent` now narrows
the new path's live + backfill audience to `user_newsletter=1` (matches legacy; legitimate
interest syncs all). **F3-48.2 DONE** — `ContactReconciler` (Smaily→WP marketing-consent
mirror, consent mode only) + `Client::get_action_log()`/`list_contacts()`. Delta-first (Erkki
resource concern): the standing `reconcile()` polls the Smaily action-log (`history.php` +
`since_seq_id` cursor) for `optin`/`optout`/`delete`/`complaint` deltas — O(changes), light on
shared hosting; `rebaseline()` (full `list=1` pull) is the occasional re-baseline only.
Marketing-only (never touches profiling `smaily_rec_profiling`). Mirrors the engine's action-log
approach (`re/docs/CONTACT_RECONCILIATION_DESIGN.md`, `re/docs/smaily-api/reference/action-log.md`);
see `docs/CONSENT_STRATEGY_COMPARISON.md`. **F3-48.3 DONE** — cron takeover:
`Bootstrap::on_contact_sync_tick` no longer fires the legacy buggy
`Cron::smaily_sync_subscribers` mass-send (the F3-47 site-locale clobber, now orphaned/dead);
it runs `ContactReconciler::reconcile()` (consent mode) + a mode-aware refresh via a
non-clearing `BackfillJob::start(false)`, guarded by `BackfillJob::should_start_refresh()`
(skip while a walk runs / re-arm once per freshness window). API errors swallowed so the tick
never fails; abandoned-cart bridge untouched. **F3-48.4 DONE** — `AutomationRouter::
trigger_automation` now passes `ContactSyncMode::automation_force_opt_in()` instead of the
hard-coded `true` default, so welcome/first_order/abandoned_cart honour the mode's consent
posture (consent/checkout → never re-subscribe; legitimate interest → only with the advanced
toggle). **F3-48.5 DONE** — Settings/wizard UI: a "Contact sync mode" Card in `Step2Subscribers`
(3 radio-card presets + legitimate-interest warning Banner + `include_guests` checkbox +
preset-1-only force-opt-in toggle), wired end-to-end (wizard reducer actions →
`buildTabPayload`/`hydrate`/`settings-reducer` → `SettingsEndpoint::save_subscribers` validated +
`EnvDetector::saved_settings` boot). Merchants now pick the preset in the UI (no longer
option-only). **F3-48.6 DONE** — consent opt-in/opt-out propagation (WP → Smaily): a
`user_newsletter` meta-transition handler (consent mode, bound to `update_user_meta` +
`add_user_meta`, reads the pre-write old value) enqueues a separate `:consent` contact-sync row
— opt-in → `is_unsubscribed=0`, opt-out → `is_unsubscribed=1`; the routine data sync never sends
`is_unsubscribed` (so a profile edit can't resurrect a Smaily unsubscribe between reconciles).
Also fixed a **latent bug found here**: the `Flusher` dropped the top-level `language` on the live
contact-sync path (only the backfill ever sent it) — now forwarded into the Smaily row alongside
`is_unsubscribed`. Regression locks added. Remaining: Prike cutover (.7) + thorough end testing.
Supersedes the earlier ad-hoc SP-D/SP-E plan (the cron takeover + `is_unsubscribed` lock fold
into this engine). Builds on F3-47.

**Relationships:** F3-47 (the mode-independent language resolver this builds on); CC-1; the
coexistence map (`setup_completed` wizard gate); the legacy `Cron::smaily_sync_subscribers`
(the buggy mass-send this retires) + its unsubscribe-pull (becomes the reconciler).

### F3-49 — Browse events carry `smaily_visitor_token` (cold-start identity), NOT rec_id/email; browse attribution stays order-signal-driven

**Context:** the browse beacon (`rec-engine-client.ts` `enrich()`) sent per event ONLY `session_id`
— no `smaily_visitor_token`/`smaily_rec_id`/`smaily_ctx`/`customer_email` — although the cookies
hold them, the `/relay` whitelist (`BeaconEndpoint::EVENT_FIELDS`) accepts them, contract §6
identity-resolution + retroactive-binding reference them, and F3-27 pt1 assumed browse carries
identity. Net effect: every browse_event resolved to §6 path-4 (anonymous, session-only); the §6
retroactive-binding-via-browse never fired; the async order-attribution path-3 ("session_id →
browse_events *with a rec_id link*") was inert. Raised by Erkki (2026-07-01) questioning an
over-stated claim that a purchase "loses" rec attribution; the investigation logged it as an open
cross-team question rather than assume.

**Engine-team answer (2026-07-03):** browse does NOT feed attribution. Order-match hierarchy is
order `smaily_rec_id` → visitor_token → email-click → browse; the block-checkout stamping fix
(v3.2.0) means path-1 catches the same case more strongly than browse ever could. The
`direct`/`exact_later`/`indirect_*` classes come from the email click + order `smaily_rec_id`;
browse would at best yield the softest `assisted_view`, and only for an already-identified
customer. Guest-browse non-binding is an accepted v1 limitation — login-merge (§7, F3-27) is the
intended binding path.

**Decisions:**
1. **No browse-level attribution.** Browse attribution rides ORDER signals; `smaily_rec_id` /
   `smaily_ctx` / `customer_email` are deliberately NOT put on browse events (redundant +
   data-minimization). Enforced CLIENT-side (`enrich()` never adds them) — the `/relay` whitelist
   still lists them (a Shopify/other wrapper may send them), so the minimization is the client's.
2. **DO carry `smaily_visitor_token` on browse events** — opaque, low-PII, omit-on-empty (mirrors
   `session_id`; most organic visitors have no token). Value is NOT attribution but future
   **cold-start personalization** (e.g. category inference from browse before the first purchase):
   data accumulates now so a v1.1 feature doesn't start from zero. The engine binds the browse row
   to the customer via the token; ingest already accepts it (whitelist + `with_customer_match`
   sub-count).
3. **Profiling opt-out on the token / external_id path is ENGINE-side** (engine-confirmed,
   server-side enforced from 2026-07-03): an opted-out contact's browse event is never bound to a
   customer on any path (stays anonymous) and identity-merge is a no-op for it. The plugin's
   email-based `ProfilingConsent` gate stays the FIRST filter; the plugin can't map
   visitor_token→email locally (engine-issued), so the token-path opt-out is inherently the
   engine's responsibility, not a plugin blocker.
4. **Guest-browse non-binding + browse-session-only = accepted v1 limitation** (Q3), documented
   here + STATUS "Known deferred items".

**Rationale:** the money question (which rec drove which purchase) is order-attributed and
unaffected; the only thing browse could add is soft assisted-view, which the engine deprioritized.
Sending just the opaque token (not rec_id/email) captures the cold-start value at minimal PII cost,
within the existing consent model.

**Alternatives rejected:** wire rec_id + email onto browse (engine says redundant; data-min);
leave browse identity-blind entirely (forfeits free cold-start data the engine asked for); build
browse-based attribution (engine confirmed no value over order signals).

**Relationships:** F3-24 (browse-beacon architecture — server proxy + whitelist), F3-27
(identity-merge — the intended binding path; §6 retroactive-binding), F3-46 (server-side landing
capture — where ORDER attribution actually originates), the §6 identity-resolution flow, §14.2
("engine consumes browse post-MVP").

### F3-50 — Browse consent stays on the standard WP Consent API; CookieYes is a config fix + an admin advisory, NOT vendor code

**Context:** MiuMjau had browse-tracking on and a healthy connection but the engine got 0
`/api/v1/ingest/browse` requests. The beacon gates fail-closed on the WP Consent API
(`beacon-core.ts` `detectConsent()` sends only when `window.wp_has_consent(category) === true`).
Live probe on the storefront: `typeof window.wp_has_consent === 'undefined'` — no signal ⇒ gate
closed ⇒ 0 events, no error (LESSONS §2.15).

**First (wrong) fix — 3.3.1, reverted.** On the assumption "CookieYes doesn't support the WP
Consent API", 3.3.1 added a CookieYes-specific fallback that read the `cookieyes-consent` cookie
directly. Erkki challenged it on two grounds — (a) per-vendor consent code is a maintenance
treadmill we deliberately avoided by standardising on the WP Consent API, and (b) CookieYes's own
docs say it DOES integrate the WP Consent API. Both correct.

**Root cause (corrected).** CookieYes integrates the WP Consent API, but ONLY when the free
companion **"WP Consent API" plugin** (wordpress.org `wp-consent-api`) is installed + active — that
plugin is what defines `window.wp_has_consent` and the `wp_has_consent()` PHP function; CookieYes
registers consent INTO it (CookieYes `Advertisement` → WP Consent API `marketing`, matching the
beacon's default category). MiuMjau simply lacked the companion plugin. So the gap is a **missing
companion plugin (config), not a CookieYes incompatibility** — and the standard path works once
it's installed, with zero plugin code.

**Decisions:**
1. **Revert the 3.3.1 CookieYes cookie-parse.** Browse consent stays purely on the WP Consent API
   (+ the `consentOverride` JS hatch + fail-closed default). No per-vendor consent code in core.
2. **The line for future CMPs:** WP Consent API is the standard/default (0 per-vendor cost); a
   bespoke adapter is justified ONLY for a dominant CMP or a live client's actual CMP that will not
   adopt the API — NOT the long tail. CookieYes does adopt it, so it needs no adapter at all.
3. **Ship an admin advisory instead** (`NotificationManager::needs_consent_api_notice`): browse on
   + connected + `! function_exists('wp_has_consent')` → a dismissible `notice-warning` telling the
   merchant to install the free WP Consent API plugin. CMP-agnostic, standard-aligned, catches the
   silent-0-events trap for every future client without vendor code.
4. **MiuMjau fix = install the companion plugin** (wp-admin, no server file access — Erkki has
   none). A mu-plugin `consentOverride` unblock was ruled out for the same reason.

**Rationale:** the ecosystem is designed so ONE integration (WP Consent API) covers all compliant
CMPs; parsing a vendor's cookie both duplicates that and takes on its format churn. The advisory
moves the fix to configuration (install a plugin) rather than code we maintain.

**Alternatives rejected:** keep the CookieYes cookie-parse as a fallback (reintroduces the
maintenance debt the revert removes, and can mask the correct config); a merchant-configurable
consent-mapping UI (a larger epic — the long-tail answer if bespoke demand ever grows, not needed
now, YAGNI); tell CookieYes merchants nothing (leaves the silent-0-events trap).

**Relationships:** F3-24 (browse-beacon architecture — the fail-closed WP Consent API gate), F3-49
(the visitor_token this unblocks once browse fires), F3-30 (NotificationManager — the admin-notice
infra reused), LESSONS §2.15 (the assume-vs-live-probe scar, now with the deeper correction that
the standard already covered CookieYes).

### F3-51 — Automations config (T2): the engine is the authority — the plugin is a stateless proxy, no local copy, no duplicate validation

**Context:** the engine can enrol Smaily contacts into merchant-built automations at the right
moment (replenishment due, win-back, …); the plugin's role is CONFIGURATION only (contract
v1.1.0 §11–§13, synced 9ec2ff8). T2.1 is the PHP layer: `Client::automations_catalog()/
automations_config()/put_automations_config()` (+ `PATH_AUTOMATIONS_*` fallbacks) and the
admin REST proxy `AutomationsEndpoint` (GET catalog / GET config / PUT config). The React
settings UI is the next sub-PR (T2.2).

**Decisions:**
1. **The engine's GET is the source of truth — the plugin stores NOTHING.** No wp_options
   copy, no transient, no catalog cache: the UI re-reads via GET on every open. A local copy
   would drift against engine-side operator edits (`configured_via: "admin"`) and against
   catalog changes that ship with engine deploys (§11 says render dynamically — a new trigger
   must not need a plugin release).
2. **No PHP-side duplicate validation on PUT.** The proxy forwards `{configs}` as-is (only a
   minimal is-array check); the engine's Zod schema is the single validator and its **422 is
   passed through verbatim**. Meaning for the UI: §13 validation is ALL-OR-NOTHING — a 422
   means NOTHING was saved (not even the valid rows); the indexed D6-style `errors[]`
   (`{index?, trigger_key?, field, message}`, wrapper-level entries index-less with
   `field:"configs"`) binds each error to its row/field, and the fix is resubmitting the whole
   corrected selection. `ApiException` already carried `errors[]` (F3-18) — no extension needed.
3. **URLs via endpoints-map key + fallback constant, like every engine call.** Map keys
   `automations_catalog`/`automations_config`; fallbacks `PATH_AUTOMATIONS_CATALOG/_CONFIG`.
   The fallbacks are load-bearing for EVERY pre-v1.1.0 connection (a stored map never gains
   keys retroactively — the contract's "Map age" note), so the endpoint factory passes
   `RecEngineSettings::endpoints()` through (the ping factory doesn't need to).
4. **Engine-error mapping in the proxy:** engine 401 → 502 `api_key_rejected` (a clear
   "stored key invalid — reconnect" answer, distinct from WP-side 403 and from unreachable);
   422 → 422 passthrough (above); other 4xx/5xx/network → 502 with the engine's error code
   (same convention as `RecEngineEndpoint::ping`). The api_key never appears in any response.
5. **Fail-closed stays with the merchant:** the plugin must never send `enabled: true`
   without an explicit merchant action and the UI defaults `test_mode` on (§11) — T2.2 UI
   requirements recorded here so the PHP layer's as-is passthrough isn't mistaken for licence
   to auto-enable.

**Rationale:** one authority (engine) + one validator (engine Zod) means zero drift surface —
the exact class of bug (mock/local copy masking the live shape) that cost the most in Phase 3
(LESSONS §2.3/§2.7). The mock engine gained the three routes with strict §11–§13 validation in
the SAME pass as the contract sync, per the sync-is-not-code-complete rule.

**Alternatives rejected:** caching the catalog/config in wp_options (staleness + a second
source of truth; the GET is cheap and admin-only); PHP-side pre-validation for friendlier
errors (duplicate rulebook that drifts — the engine's indexed 422 is already field-precise);
POST with a custom verb param instead of PUT (the contract says PUT; `wp_remote_request`
carries a body on PUT fine, unit-pinned).

**Relationships:** F3-18 (ApiException preserves `errors[]` — reused), CC-1 (URL
single-source via map+constants), F3-28.6/LESSONS §2.9 (endpoints-map placeholder discipline
— no placeholders in these URLs, plain keys), LESSONS §2.7 (mock moved in the same sync).

### F3-52 — Automations config UI (T2.2): joint Save with split dirty state; upsell when unconnected; going live is a confirmed separate act

**Context:** T2.2 renders the F3-51 proxy as the "Engine-run recommendation automations"
sub-section UNDER the store-run WooCommerce automations (Step 3 / WooCommerce tab,
`EngineAutomationsSection`), catalog-driven per §11 (no hardcoded trigger keys — an
engine-deployed trigger appears without a plugin release; docs link from the catalog's
`docs` field; `_et`/`_en` copy picked by the admin locale via `<html lang>`, `recipe_et`
always shown). Design decisions locked by Erkki 2026-07-07.

**Decisions:**
1. **One Save button, TWO parallel requests, SPLIT dirty state.** The engine section joins
   the WooCommerce tab's sticky-footer Save (and the wizard Step-3 Continue): Save fires the
   existing `POST /settings` plus — when the engine slice is dirty — a `PUT` through the
   automations proxy, in parallel. The engine slice keeps its OWN dirty bit
   (`state.engineAutomations.dirty`, deliberately NOT `dirtyTabs.woocommerce`): on a partial
   failure (local POST ok, engine PUT failed) only the engine section stays dirty, its error
   renders inside the section, and Save stays enabled for the corrected resubmit — the
   merchant loses neither half. §13 all-or-nothing means a 422 keeps the WHOLE slice dirty.
   `saveEngineAutomations()` (state/engine-automations.ts) is the single orchestrator both
   save paths call, so Settings and wizard can't drift.
2. **Not connected → modest upsell, context-aware CTA.** In Settings the banner's CTA jumps
   to the Campaign Intelligence tab (hash routing); in the wizard the copy points to the
   NEXT step (connection happens in Step 4), and Step 4 shows a post-connect banner offering
   the way back to Step 3 (`WIZARD_GO_TO_STEP` — the existing navigation). A proxy 503 at
   fetch time renders the same upsell state.
3. **Going live is a separate, confirmed act (fail-closed §11).** `test_mode` defaults ON
   for a never-configured trigger; "Activate for real…" is its own button with a
   `window.confirm` naming the consequence — never the enable toggle. `enabled=true` only
   ever comes from the merchant's explicit toggle click.
4. **The engine's GET is the truth in the UI too.** The section fetches catalog+config on
   every open (unmount on tab switch, no cache); a dirty draft survives the re-fetch, a
   clean slice is replaced. Rows live in state in the EXACT §13 wire shape (snake_case,
   scar 3.5.3a) and the PUT sends every rendered row with all 8 fields — un-edited fields
   (today `daily_cap`) round-trip from GET unchanged, the §12 read-only pair is stripped at
   hydrate so it can't leak into the PUT. A config row absent from the catalog is neither
   rendered nor sent (PUT never deletes). Client-side pre-validation at the fields is a UX
   layer only; the engine's indexed 422 is the validator and binds back to rows by
   trigger_key/index (F3-51 rule 2 unchanged).

**Rationale:** one Save keeps the merchant's mental model ("this tab saves together") while
the split dirty bit + section-local errors make the two destinations' independent failure
modes visible instead of averaging them into one banner; the confirm-gated live switch keeps
the §11 fail-closed promise a UI invariant, not a convention.

**Alternatives rejected:** a separate Save button for the engine section (two buttons on one
tab reads as a bug; the partial-failure semantics give the same safety); folding engine dirty
into `dirtyTabs.woocommerce` (can't express "local saved, engine still dirty"); blocking the
local POST when engine pre-validation fails (would hold the store-run automations hostage to
an engine-side field error).

**Relationships:** F3-51 (the proxy + engine-is-authority rules this UI renders), F3-29
(Step-4 connect flow the upsell/back-banner integrates with), scar 3.5.3a (snake_case wire
types end-to-end), LESSONS §2.3 (mock strictness — component tests mock the api module, the
shapes come from the contract).

**Addendum (2026-07-07, T2.4 — Erkki's real-store test): language mode is STORE-GLOBAL;
a server row's `language_mode` is only a wire fact.** The display mode is derived ALWAYS
and ONLY from the store's structure (`deriveLanguageMode`: multilingual A/B → per_language,
else single) and applies uniformly to every row. The stored `language_mode` on a §12 config
row records how that row was last saved — it is never honoured as a display shape. (The bug:
a walk-saved `replenish_due` row with `language_mode='single'` rendered ONE dropdown on the
sandbox while its never-configured neighbours rendered the per-language table — two shapes
on one screen.) At hydrate, `convertAutomationMap` translates a stored map into the derived
mode: single `{id}` → per_language `{fallback: id}` (language fields start unpicked; the
workflow stays reachable and the row valid); per_language → single `{id: fallback}` (the
language split is meaningless on a single-language store; a map without a fallback converts
to `{}` and the merchant re-picks — validation flags it while enabled). The PUT always sends
the derived mode + the converted map, so the engine row converges to the store's shape on
the next save. Alternative rejected: honouring the server row's mode per row (what shipped
in 3.4.0) — it renders an inconsistent mixed UI and lets a stale wire fact override the
store's actual language structure.

### F3-53 — Abandoned-cart poison-row hardening + the legacy WP-Cron scheduler is removed for good (Prike fatal loop)

> **Note (PRO-1195, 2026-07-11):** the "rewrite is a separate decision"
> deferral is now resolved — the legacy abandoned-cart pass is retired and
> the poison-row guards + Throwable backstop carry over into the drain
> (`LegacyCartDrain`), the sweeper and the CartFlusher. Decisions 2–3 here
> (no legacy scheduler, uninvocable mass-send) stand unchanged.

**Context:** Prike (2026-07-08) installed the new module over the old one (no in-place
upgrade). From minutes after their setup wizard: a PHP 8 fatal (`Cannot access offset of
type string on string`) on every 15-minute `smly_plus_abandoned_cart` tick — old-writer
`cart_content` rows deserialize to an array of bare STRINGS, and the legacy
`prepare_products_data()` read `$cart_item['product_id']` unguarded. The failing cart
stayed `mail_sent NULL` (retried forever) and the fatal aborted the whole pass (F3-37's
per-cart `continue` covered API errors, not Throwables). Separately, the legacy WP-Cron
events were alive in their cron option: `Lifecycle::activate()` and `activated_plugin`
(`check_for_dependency`, any WooCommerce re-activation) re-scheduled them AFTER
WPCronAuditor's one-time activation clear — and `Cron::smaily_sync_subscribers` was still
`add_action`-registered, so the surviving daily event ran the F3-47 language-clobber
mass-send that F3-48.3 had declared "orphaned, dead, harmless."

**Decisions:**
1. **Poison rows are terminal-marked, never eternally retried.** Non-array
   `cart_content` → log + `update_mail_sent_status` + continue. Non-array / keyless
   cart ITEMS are skipped item-level (the cart itself still sends). A per-cart
   `try/catch (Throwable)` backstop terminal-marks a throwing cart — a data-shape
   throw is deterministic and would recur every tick. Observable in the log, never
   a silent infinite loop (LESSONS §2.11 spirit).
2. **The legacy WP-Cron scheduler is deleted, not just its events cleared.**
   `Lifecycle::set_scheduled_actions()` and both call sites removed; scheduling is
   owned by the AS `smly_plus_*` recurring actions. `deactivate()`'s clears stay as
   residue defense. A version upgrade heals an already-polluted site
   (`maybe_run_upgrade → Activation::run → WPCronAuditor` clear) and nothing re-arms.
3. **The legacy subscriber mass-send is made UNINVOCABLE.** The
   `add_action('smaily_connect_cron_sync_subscribers', …)` registration is removed
   (method body stays for the upstream diff). F3-48.3's intent becomes structural: no
   scheduler event, stray or future, can fire the cron-unsafe language path again.

**Rationale:** every layer that "should never happen" happened at once on a real client;
each fix removes a whole class (untrusted persisted shape, poison-pill pass abortion,
resurrectable legacy scheduler) rather than the single symptom.

**Alternatives rejected:** rewriting abandoned-cart onto the new namespaced pipeline now
(bigger scope; hardening makes the legacy path safe and the bridge keeps business logic
unchanged — a rewrite is a separate decision); leaving a throwing cart `mail_sent NULL`
for retry (deterministic error ⇒ infinite 15-min loop, the exact bug); keeping
`set_scheduled_actions` as a no-op shell (dead code invites re-wiring; the deactivate
clears document the hook names already).

**Relationships:** F3-37 (backlog guard + per-cart API-error handling this extends),
F3-47/F3-48.3 (the language clobber this permanently de-fangs), sub-PR 5.D /
WPCronAuditor (the migration whose one-time clear the re-arm defeated), LESSONS §2.18.

**Addendum (2026-07-08, same day): the abandoned-cart `language` field routes through
ContactLanguageResolver.** Reviewing the pass for the fix above surfaced the same F3-47
class ON THIS PATH: `prepare_user_data()`'s language case called the legacy
`Helper::get_user_language_code()`, whose fallback is the context-dependent
`get_current_language_code()` — in this cron/AS pass that resolved '' (or the wrong
context), and Smaily treats `language: ''` as "wipe the contact's stored language"
(smaller scale than F3-47 — only abandoned-cart contacts missing per-user meta, but the
same wipe/clobber, every 15-minute tick). Now: `ContactLanguageResolver::for_user()`,
omit the key when it returns '' (F3-47 rule 2). This is the interim fix; the full
abandoned-cart rewrite onto the new namespaced pipeline is a separate future decision.

### F3-54 — Abandoned-cart status option: one normalized shape, router-first dispatch (the REAL Prike fatal)

> **Note (PRO-1195, 2026-07-11):** the legacy email pass that hosted the
> router-first dispatch is retired; the normalized option read, the
> router-first order and the preserved `autoresponder_id` fallback all carry
> over into the new pipeline (`CartFlusher`). Decision 1 and 3 stand as-is.

**Context:** Martin's (Prike dev) correction to the F3-53 diagnosis: the PHP 8 fatal was at
the EMPTY-OPTION GUARD (`$status['enabled']`, cron.class.php:166), not in the cart-item
loop — and turning the feature off in wp-admin didn't stop it. Root cause is OUR seam:
`SettingsEndpoint::save_woocommerce()` wrote `smaily_connect_abandoned_cart_status` as a
BARE BOOLEAN (WP stores `'1'`/`''`), while three consumers offset into it as an ARRAY
(the legacy email pass, `Options::get_woocommerce_settings_from_db()`, and — inverted —
`EnvDetector`'s `(bool)` cast, which read a DISABLED array as enabled). A string offset
with a string key is a PHP 8 TypeError (repro'd: both `'1'['enabled']` and `''['enabled']`
throw "Cannot access offset of type string on string"). Toggling off just wrote the other
string. The old guard test never caught it because it seeded the option ITSELF in the
array shape — the fixture's shape, not the real writer's. The new save also DESTROYED the
legacy `autoresponder_id`, and no producer feeds `automation.abandoned_cart` — so the
legacy email pass is the only abandoned-cart sender, reading a workflow id the new UI no
longer maintains.

**Decisions:**
1. **One normalized read path.** `Options::abandoned_cart_status()` (+ pure
   `normalize_abandoned_cart_status()`) accepts array, bare-boolean-string, and garbage,
   always returning `{enabled: bool, autoresponder_id: int}`. Every consumer (cron pass,
   get_woocommerce_settings_from_db, EnvDetector hydrate) reads through it — never a raw
   `get_option` + offset. Heals already-corrupted stores (Prike) with no manual step.
2. **Router-first dispatch in the legacy email pass.** The pass now tries
   `AutomationRouter::trigger_automation('abandoned_cart', …)` first — the wizard's
   automation-mapping row is the workflow source on new-path stores (multilingual modes,
   the F3-48 force_opt_in policy, F3-44 exchange capture come free; the router docblock
   always claimed this trigger). `ApiException` = transient → cart stays unmarked for
   retry (same semantics as the legacy error-array path). **Fallback:** no mapping row +
   a non-zero legacy `autoresponder_id` → the legacy client path, unchanged — zero
   regression for upgraded-but-unwizarded stores. Enabled with NEITHER source = config
   gap: carts stay pending (they send once the merchant maps a workflow; the backlog
   guard bounds the pile), logged once per pass.
3. **The writer produces the array shape and PRESERVES `autoresponder_id`** — it is the
   no-mapping fallback; destroying it (what 3.4.x did) silently killed upgraded stores'
   abandoned cart even where the shape didn't fatal.

**Rationale:** normalize-at-the-reader alone would have silently disabled the feature on
every new-path store (boolean shape carries no workflow id); router-first makes the
mapping table the id source so the feature actually works post-wizard.

**Alternatives rejected:** keeping the boolean option and porting all consumers to it
(loses the fallback id upgraded stores still need); building the
`automation.abandoned_cart` queue producer now (the right end-state, but a bigger change
than a crash-fix release should carry — folded into the BACKLOG rewrite item).

**Relationships:** F3-53 (the incident; the poison-row hardening stays as defense in
depth), F3-48.4 (force_opt_in policy now applied to abandoned cart via the router),
F3-44 (router sends are exchange-captured), LESSONS §2.19, the BACKLOG abandoned-cart
rewrite item (the queue-producer end-state).

### F3-55 — Backfill progress: users WALKED and contacts SYNCED are different numbers with different labels

**Context:** Prike (2026-07-08): "contact sync shows 30k contacts going to Smaily, we have
16k opt-ins." The wire was correct — the F3-48 audience filter POSTs only the mode's
audience — but `total_count` = `count_users()` (every WP user), `processed_count` counts
rows WALKED, and the UI labelled that walk count "contacts synced"
(`Step2Subscribers`, `Step6Done`). On a consent-mode store the label reads as a consent
violation.

**Decisions:**
1. **Two numbers, both shown, each with its own noun.** The walk (`processed/total`)
   keeps driving percent + ETA — it is monotonic and always completes, where an
   audience-based denominator would freeze through opted-out ID ranges and drift when
   users opt in/out mid-run. A new cumulative `synced_count` (migration 008) counts
   AUDIENCE members handled (POSTed + already-fresh); it is the ONLY number the UI may
   label "contacts synced". Copy: running "Checked X of Y users — Z contacts synced",
   done "Done — Z contacts synced (X users checked)".
2. **The audience definition has one home.** `ContactAudience::count_audience()` (the
   SQL count) lives next to `should_sync_user()` (the per-user predicate) and an
   integration test asserts they agree in every mode — two implementations of one
   definition must not drift (CC-9 spirit).
3. **The estimate is shown BEFORE the run** ("about N of them will be synced to Smaily
   as contacts"), only when the mode actually narrows the audience, and is computed
   only on non-running status polls (no usermeta COUNT per 2-second tick).
4. Engine backfills (products/customers/orders) are untouched — they enqueue everything
   they walk and already show engine-confirmed `sent` (3.10.0); `synced_count` stays 0
   for them and their UI ignores it.

**Rationale:** a count a merchant sees must be denominated in the unit its label claims;
"walked" sold as "synced" made a working consent feature look broken.

**Alternatives rejected:** setting `total_count` to the audience size (percent/ETA freeze
on skip-heavy stretches, completion drift when consent changes mid-run); relabeling the
existing number without adding `synced` (hides the number the merchant actually wants).

**Relationships:** F3-48 (the audience filter whose correctness this makes visible),
3.10.0 engine-confirmed counts (the same walked-vs-confirmed distinction for engine
jobs), migration 008.

### PRO-1224 — Product `sku` is ALWAYS `woo-<id>` (platform id), never the merchant SKU; catalog rows carry `tags.product_id`

**Context (2026-07-10):** the engine sharpened the contract's identity rule
(§3, v1.3.0): the ingest `sku` is a **join/identity key, not a human SKU**. On
Shopify (PRO-1223) the plugin's "fall back to the merchant SKU field" collapsed
Urban Green's catalog 605→330 rows — distinct products sharing a blank/reused/
garbage merchant SKU (`"63.00"`, `"12"`, an EAN) landed on one `(tenant, sku)`
key and silently overwrote each other. Woo had the same defect. The engine is
adding fail-loud namespace validation that rejects off-scheme keys.

**Decision:** `Support\SkuResolver::resolve()` ALWAYS emits `woo-<canonical_id>`
(variation id for a variable product, product id for a simple one), prefix
`woo-`, **never** the merchant WC SKU field — not even as a fallback. This
REVERSES F3-36's "real SKU when set, else `wc-{id}`" (prefix and the merchant-SKU
branch both gone); the resolver *pattern* — one chokepoint for catalog + order +
browse, translation canonicalization (CC.2), the never-drop deleted-line fallback
now `woo-oi-<item_id>` (F3-43) — is unchanged. Additionally, every catalog row
now carries `tags.product_id` = the RAW (un-prefixed) canonical PARENT product id
(`SkuResolver::product_group_id()`): the cross-variant grouping key (PRO-1227) and
the product-level removal key (`catalog/remove` §3b, PRO-1230). The raw platform
id continues to ride in `external_id`.

**Rationale:** the merchant SKU is optional/blank/reused on real stores; keying on
it destroys history. The platform id is stable, unique, and namespaced so `woo-`
and `shp-` never cross-join. `tags.product_id` is RAW (not `woo-`-prefixed) for
deliberate parity with Shopify Connect (which ships `tags.product_id = product.id`,
the raw legacyResourceId) and the §3b contract example (`product_ids: ["7620134"]`)
— caught by reading the shipped Shopify code + contract, NOT trusting PRO-1230's
looser "`woo-<product_id>`" phrasing (LESSONS). The merchant SKU is dropped
ENTIRELY (engine answer PRO-1225, 2026-07-10): the engine consumes it nowhere;
if ever needed it goes in `tags.merchant_sku`, never `external_id` (that field is
the platform variant id + drives collision detection) or `sku`.

**Alternatives rejected:** a tenant-level "key mode" setting (merchant-SKU vs
platform-id) — more code, and there is no case where keying on the merchant SKU is
correct; emitting the merchant SKU in `external_id` for display — the engine
surfaces it nowhere, so it is wire + storage for no consumer (data-minimization).

**Migration:** UPSERT-only means old `wc-<id>`/merchant-SKU rows are NOT
auto-removed — the new `woo-<id>` keys create fresh rows and the old ones linger.
Orphan removal is a one-time manual purge on the engine side, coordinated per
already-synced store (contract §3 "changing the SKU scheme"). The pilot is
SKU-less, so every key changes `wc-<id>`→`woo-<id>` — it needs the purge + a full
re-backfill before/at the flip.

**Relationships:** supersedes F3-36 (the pattern lives on); contract v1.3.0 sync
(commit `a5c3ea6`); PRO-1223 (engine fail-loud namespace validation), PRO-1225
(merchant-SKU/external_id engine answer), PRO-1227 (engine groups by
`tags.product_id`), PRO-1230 (hard-delete → `catalog/remove` §3b, consumes
`tags.product_id`); Shopify PRO-1226 (parity).

### PRO-1230 — Hard-deleted parent product → §3b `catalog/remove` (product-level tombstone); trash and variation deletes keep the soft path

**Context (2026-07-10):** contract v1.3.0 added `POST /api/v1/ingest/catalog/remove`
(§3b): a soft tombstone — `in_stock=false` + `recommendable=false` on every catalog
row whose `tags.product_id` matches; rows are kept (learning corpus), never
hard-deleted. This is the designed path for a platform HARD delete, where the
product's variants/SKUs are no longer enumerable. Until now a permanent delete only
got the F3-40 in_stock=false upsert (captured at before_delete_post) — the product
could still linger recommendable-adjacent, and F3-40 explicitly named the hard-delete
gap as accepted.

**Decision:** `before_delete_post` now routes to `CatalogHookHandler::
on_hard_delete_product` (Bootstrap rebind; `wp_trash_post` stays on the F3-40 path
untouched), which routes by post type:
- **PARENT product** (incl. a purge-from-trash — that also fires before_delete_post):
  ONE `catalog.remove` queue row whose payload `product_id` is `SkuResolver::
  product_group_id()` — the RAW un-prefixed CANONICAL parent id, byte-identical to
  the `tags.product_id` the catalog sync stamps (engine confirmed 2026-07-10:
  removal matches the exact string stored in `catalog.tags.product_id`; the issue's
  original `woo-<product_id>` wording was an error). The per-SKU `catalog.delete`
  rows are NOT also enqueued: §3b is strictly stronger, and an in_stock=false UPSERT
  racing the tombstone across two independent flush cycles is avoidable wire noise.
  The handler also PRE-CLAIMS the per-request `catalog.delete` dedupe slots of the
  product's variations — WC cascade-deletes them right after the parent, each firing
  before_delete_post; without the pre-claim each would enqueue a redundant soft
  removal.
- **Single VARIATION** (parent lives on): keeps the existing per-SKU soft path
  (`on_delete_product` → in_stock=false). §3b is PRODUCT-level — firing it for one
  variation would wrongly tombstone all surviving siblings.
- **TRANSLATION of a surviving canonical**: re-sync the canonical (P4), same as trash.
- **auto-draft**: skipped (the daily GC burst was never ingested; each §3b call would
  be a not_found round-trip for nothing). Other never-ingested statuses may still
  fire a removal — idempotent, `not_found` is a contract-defined success.

**Plumbing:** new event type `catalog.remove` through the shared IngestQueue, drained
ONLY by the new `CatalogRemoveFlusher` (own AS hook `smly_rec_flush_catalog_remove` /
group, 60s recurring tick; IngestFlusher's catalog.upsert/delete scoping is untouched).
§3b is NOT D6 — the response is `{ok, removed_products, rows_tombstoned, not_found}`
with no per-item errors[] — so `AbstractD6Flusher` grew a protected `apply_response()`
seam (default = the D6 split); the remove flusher overrides it to mark every batched
row SENT on 2xx, storing the per-row outcome (`removed` / `not_found`) in the F3-44
exchange. HTTP failures inherit the shared terminal-4xx / transient-retry policy; a
keyless row is an observable terminal skip (LESSONS §2.11). `Client::catalog_remove()`
resolves `endpoints[ingest_catalog_remove]` with fallback
`PATH_INGEST_CATALOG_REMOVE` — the v1.3.0 endpoints map does not advertise the key, so
the fallback serves every current connection ("Map age", contract §1). The mock engine
gained the §3b route in the same pass (CC-8): wrapper validation (1..1000, 400 on
malformed) + exact-string matching against the `tags.product_id` values it ingested.

**Rationale for enqueue-not-inline:** hooks must never block on HTTP (established
architecture); the queue gives durable retry + Event Log observability. Removal is
best-effort by contract ("the periodic full re-sync is the reconciler"), so a lost
fast-path event self-heals.

**Alternatives rejected:** (a) sending BOTH §3b and the per-SKU in_stock=false rows on
hard-delete — redundant, plus the cross-flusher ordering hazard above; (b) firing §3b
for a variation delete with the parent id — tombstones surviving siblings; (c) a
separate queue table — the shared queue's event-type scoping already isolates
flushers.

**Migration note:** §3b matches on `tags.product_id`, which rows synced BEFORE
PRO-1224 don't carry — those return `not_found` until the coordinated purge + full
re-backfill (the PRO-1224 migration) has run. Acceptable: same stores, same window.

**Relationships:** PRO-1224 (`tags.product_id` is the removal key; SkuResolver::
product_group_id), PRO-1229/PRO-1228 (contract v1.3.0 sync `a5c3ea6`), F3-40 (trash
soft path unchanged; its "hard-delete gap accepted" limit is now closed), F3-44
(store_exchange per row), F3-18/N-7 (the D6 base this deliberately deviates from,
via the apply_response seam).

### PRO-1241 — All order money fields are GROSS (tax-inclusive), per contract v1.4.0 §5

**Context:** engine-side read-only verification on MiuMjau (PRO-1202) found the
Woo plugin internally inconsistent within a single order: `total_amount` was the
gross grand total (`WC_Order::get_total()`) while `items[].line_total` used
`$item->get_total()` — which in WooCommerce is **NET (ex-tax)**. Median
`total_amount / Σ line_total` = 1.310; median `unit_price / catalog.price` =
0.806 ≈ 1/1.24 (Estonian VAT) — per-SKU revenue in Insights understated ~24% vs
the order-total revenue on the same page. Contract v1.4.0 (§5 "Amount
semantics", engine `2dec424`, synced `434ffee`) standardizes ALL money fields on
the orders endpoint as **gross/tax-inclusive** — what the customer paid; Shopify
and Magento already conform.

**Decision:** `OrderPayloadBuilder` (the single money chokepoint — live hook,
retry flusher and order backfill all build through it at send time) serializes:
- `items[].line_total` = `get_total() + get_total_tax()` (charged amount incl.
  the line's tax share, after line discounts), rounded to 4 decimals;
- `items[].unit_price` = the same gross line basis ÷ qty (NO LONGER the
  pre-discount `subtotal / qty` — §5 defines unit_price on the post-discount
  gross basis);
- `items[].discount_amount` = `(subtotal + subtotal_tax) − (total + total_tax)`
  (the gross delta), omitted when zero;
- order `discount_amount` = `get_total_discount( false )` (tax-inclusive; the
  parameterless default is ex-tax);
- `total_amount` stays `get_total()` — it was already gross (incl. shipping).
No wire-SHAPE change (same keys); values change basis. Sender invariant
`Σ items[].line_total + shipping ≈ total_amount` is pinned by unit tests
(taxed multi-line + discounted + zero-tax + rounding edge) and an integration
test driving the REAL WC tax engine (24% VAT, coupon, taxed shipping). The mock
deliberately does NOT reject on the tax basis — the live engine doesn't either
(the invariant is an engine-side monitoring signal, not a 4xx); pinning lives in
the payload assertions + the live-walk.

**Rationale:** "gross = what the customer paid" is the only basis consistent
with `total_amount`'s de-facto meaning across all three platforms, and the only
one that makes per-SKU revenue sum to order revenue on the same report.

**Alternatives:** (a) keep net lines and have the engine gross them up —
rejected: the engine stores amounts as sent and never recomputes tax (§5);
(b) send both net and gross — rejected: no consumer for net, wire bloat.

**Migration:** already-ingested MiuMjau orders keep the net basis until the
one-time historical re-sync — engine-coordinated, riding the PRO-1233 window
(~2026-07-14); re-ingest fully replaces line items (§5 idempotency), so the
backfill self-corrects rows. NOT part of the plugin change.

**Supersedes** the amount serialization shipped with F3-22/W5 (net
`get_total()` lines, pre-discount `subtotal/qty` unit_price, ex-tax
discounts). Status mapping and everything else in F3-22 stand.

**Relationships:** F3-22 (orders milestone — amounts part superseded), F3-43
(deleted-line snapshot totals now also gross via the same accessors), CC-8
(contract sync `434ffee`), LESSONS §2.3 (a formatted-field basis is exactly the
mock-vs-live class the live-walk must cover).

### PRO-1195 — Abandoned cart rewritten onto the namespaced pipeline; the legacy pass is retired (design approved by Erkki 2026-07-11)

**Context:** the legacy 1.x abandoned-cart path stored `serialize(get_cart())`
(whole WC objects — the exact F3-53 poison class), tracked only logged-in
users, and had no Event Log observability. F3-53/F3-54 hardened it during the
Prike incident but explicitly deferred the rewrite ("a separate decision").
This is that decision.

**Decision — the pipeline (mirrors the customers-domain shape):**
`CartHookHandler` (WC cart hooks) → `smly_plus_cart_session` tracker
(migration 009; one row per WC SESSION — cart_token = the session customer id,
so GUESTS are tracked; own scalar shape `[{product_id, variation_id,
quantity}]`, never a serialized object) → `CartAbandonmentSweeper` on the
EXISTING `smly_plus_abandoned_cart` AS 15-min tick (same option-driven cutoff
`smaily_connect_abandoned_cart_cutoff` + the F3-37 backlog guard, same filter
name) → ONE `automation.abandoned_cart` event in the Smaily EventQueue →
`CartFlusher` on its own AS action (`smly_plus_flush_cart_events`, 60 s),
event-type-scoped both ways (`EventQueue::pending()` grew only/exclude type
args; the main Flusher excludes the cart type). Dispatch keeps the F3-54
order: AutomationRouter first (wizard mapping, multilingual, force_opt_in),
fallback the legacy option's `autoresponder_id` with `force_opt_in=false`;
neither source = terminal skip with an Event Log "skipped" marker. Every row
stores its F3-44 exchange. Language: `ContactLanguageResolver` only —
`for_user`, or the new `for_guest()` (default-tier, clamped) for guests;
omit-on-empty. Wire fields keep exact legacy parity (`is_abandoned_cart`,
`store`, names, the prefilled `product_<field>_1..10` matrix +
`over_10_products`) — merchants' existing autoresponder templates keep
rendering unchanged.

**Guest carts (new capability):** a cart syncs only once an email identity is
known — the logged-in user, a session-persisted billing email, or a
checkout-entered email (classic `woocommerce_checkout_update_order_review`
POST / Store API `cart_update_customer_from_request`). No browse/visitor-token
identity in this pass (deliberate). Email-less rows are tracked but expire
under the backlog guard; a login that migrates the cart to a new session token
deletes same-email guest remnants (no double reminder).

**Upgrade continuity (definition of done):** zero reconfiguration — the new
code reads the SAME options (status array via the F3-54 normalized read,
cutoff, fields); zero lost carts — `Migration\LegacyCartDrain` runs once from
`Activation::run` (option stamp `smly_plus_cart_legacy_drained`), copying
every `mail_sent IS NULL` legacy row into the tracker with its ORIGINAL
`cart_updated` (recent carts remind, stale ones expire — the F3-37 semantics
the legacy pass would have applied). The drain is READ-ONLY on the legacy
table, treats `cart_content` as untrusted wire input (F3-53 guards + per-row
Throwable backstop) and schedules NOTHING (the F3-53 re-arm scar). The legacy
table is NOT dropped (safe rollback; schema removal is a later one-way door).

**Retirement:** the legacy `Cart` tracker and the Cron abandoned-cart
add_actions are deregistered (methods stay for the upstream diff) — F3-53
discipline: a stray surviving legacy WP-Cron event must find nothing to fire
(it would double-remind against the new pipeline). Bootstrap's
`on_abandoned_cart_tick` no longer bridges to the legacy hook names.
`AbandonedCartGuardTest` (which drove the retired pass) is replaced by
`CartPipelineTest` + `LegacyCartDrainTest` + the CartFlusher/Sweeper unit
suites — every pinned bug class (backlog guard, poison rows, per-cart
continue, the F3-54 writer↔reader seam) is re-pinned against the NEW code.

**Gates:** `setup_completed` (contact-path rule — dispatch needs wizard
credentials; tracking is additionally gated on it so an un-wizarded store
collects no cart PII) AND the merchant's abandoned-cart toggle. Housekeeping
(expiry/prune, order-completion clears) runs ungated.

**Deliberate deltas from legacy behavior (recorded, not user-visible-parity
breaks):** (1) enabled-but-unconfigured carts terminal-skip observably in the
Event Log instead of silently retrying until the age window (consistent with
the pipeline's no-workflow semantics; the config gap is logged once per
flush); (2) a deterministic non-101 Smaily body code on the fallback path is
terminal `failed` (Event Log-retryable) instead of an eternal 15-min retry;
(3) a truly-never-wizarded store's carts no longer send (the F3-54 legacy
fallback kept them working) — accepted per the approved design's
setup_completed gate; all real stores are wizard-based.

**Alternatives rejected:** per-cart AS single actions for abandonment
detection (an unschedule/reschedule per cart update on a hot path; the 15-min
sweep matches legacy granularity); keying the tracker by user/email (loses
guest carts — the whole point); draining legacy rows into the queue directly
(skips cutoff/backlog semantics); dropping the legacy table (one-way door,
blocks rollback).

**Relationships:** supersedes F3-53's "harden, don't rewrite" stance (the
hardening ideas carry over as drain/sweeper/flusher guards); F3-54 (normalized
option read + router-first order + preserved autoresponder_id all carried
over; the legacy email pass it patched is retired); F3-37 (backlog guard —
same filter, now in the sweeper); F3-44 (exchange capture); F3-47 (resolver
language, `for_guest` added); Linear PRO-1195.

**Addendum — PRO-1680 (2026-08-04): product details are ALWAYS sent; the
field selection no longer governs them.** The "upgrade continuity" rule above
had `CartPayloadBuilder` read the product slots out of the same
`smaily_connect_abandoned_cart_fields` option as the address fields. Every
`product_*` key in that option defaults to FALSE and **no UI writes the option
at all** (`Options::get_settings()` exposes it as `cart_options`, nothing
consumes that; the React Settings/wizard never posts it), so on a fresh
install the reminder went out with the whole `product_<field>_1..10` matrix
empty — an abandoned-cart email with no cart in it. Only a store carrying a
value forward from a version that HAD the selector sent anything.
**Owner decision (Erkki):** product details are always sent, with no
merchant-facing choice — so `product_fields()` now fills every
`PRODUCT_KEYS` entry unconditionally and never reads `$sync_fields`; a stored
selection from an older version is ignored rather than migrated. The address
fields (`store`, `language`, `first_name`, `last_name`) keep reading the
option — untouched, out of scope. Adding a selector UI was explicitly rejected
(it is a template concern: the Smaily template decides what to RENDER, the
wire always carries everything). This aligns the cart builder with
`TransactionalPayloadBuilder`, which already sent its whole matrix
unconditionally. **The full-matrix send is load-bearing, not cosmetic:** the
Smaily contact keeps whatever the last send wrote, so writing all 10 slots —
unused ones as `''` — is what clears the PREVIOUS cart's details from the
contact. Empty slots must therefore stay ON the wire; "don't show empty rows"
is the template's job. Demonstrated in `CartPipelineTest` against the real
pipeline (two carts for one shopper; the earlier cart's products appear
nowhere in the second reminder) — confirmed to fail with the fix reverted.
Linear PRO-1680.

**Addendum — PRO-1729 (2026-08-04): the NAMES are always sent too, but
OMITTED when unknown.** The same never-written selection also gated
`first_name`/`last_name`, whose keys likewise default to FALSE — so on a fresh
install the reminder carried no name either, and a Smaily template's
first-name merge tag rendered nothing. **Owner decision (Erkki): the same rule
as the products** — the names always ride the reminder, with no merchant-facing
choice, and a stored selection from an older version is ignored rather than
migrated. **The one deliberate difference from PRO-1680:** a `product_*` slot
is a CART field and is sent EMPTY on purpose (overwriting all ten is what
clears the previous cart from the contact), whereas the names are CONTACT
fields, where the F3-47 omit rule governs — an ABSENT field leaves the Smaily
contact's value intact, an EMPTY one WIPES it. So a name we don't know is
omitted, never sent as `''`: a nameless shopper's reminder must not erase a
name the contact already carries (one the contact sync put there, say). The
source is unchanged — the WP profile name for a known user, else the
checkout-captured `first_name`/`last_name` columns on the tracker row (the
guest path; the legacy pass had registered users only, so this is strictly
more than legacy ever sent). `store`/`language` keep reading the option:
unlike the names they default to TRUE, so they are neither unreachable nor
broken on a fresh install — out of scope, untouched. Demonstrated in
`CartPipelineTest` against the real pipeline with the transport faked and NO
stored selection (a named shopper's reminder carries the name; a nameless
one's omits both fields; a guest's checkout-typed name rides too) — the send
cases confirmed to fail with the fix reverted. Linear PRO-1729.

### PRO-1194 (sign-off) — Privacy-policy template: legal entity, URL, lawful-basis framing confirmed

**Context:** the merchant privacy-policy template in `docs/DATA_MODEL_GDPR.md`
(added PRO-1194, drafted from verified plugin behavior) shipped with three
open items blocking sign-off: the Smaily legal-entity name, the Smaily
privacy-policy URL, and confirmation of the drafted lawful-basis framing.

**Decision (Erkki, 2026-07-14):** entity = **Sendsmaily OÜ**; URL =
**https://connect.smaily.com/privacy** (a separate cross-team issue, PRO-1406,
is making this URL platform-agnostic — it currently reads Shopify-specific —
but the URL itself is stable, so the template does not wait on that rework);
lawful-basis framing = **confirmed as drafted**, legitimate interest
(Art 6(1)(f) GDPR) for profiling/personalisation with the Art 21 right to
object implemented as the opt-out (My Account toggle + engine-side
enforcement). The merchant-legal-review caveat is **not** removed by this
sign-off — every merchant must still have their own counsel review the
adapted text for their store, including documenting a legitimate-interest
balancing test.

**Rationale:** these were the only items an engineering decision couldn't
resolve alone (a real legal-entity name, a real URL, and a lawful-basis
choice all require the business owner, not an inference). With them
resolved, the template is no longer a draft and can be ported to the
merchant-facing docs site.

**Relationships:** finalizes the PRO-1194 draft (see `docs/DATA_MODEL_GDPR.md`
"Merchant privacy-policy template"); the retention-period item of the same
placeholder list was already resolved 2026-07-12 (engine team answer). Ported
to `docs/site/index.html` (EN+ET) in the same commit as this entry.

### PRO-1388 — `smaily_vt` capture-timing race: server-side capture existed (F3-46); browser-side capture made consent-independent too (2026-07-17 update)

**Context:** the engine team flagged (PRO-1382, prod-verified against
MiuMjau) that `beacon-core.ts`'s `init()`/`captureUrlParams()` is
client-side and runs exactly once, gated on marketing consent: a visitor who
decides the cookie-consent banner on a page AFTER the `?smaily_vt=...`
landing loses the URL param, so the visitor-token cookie never gets written
and the browse events eventually sent (once consent is granted) never carry
`smaily_visitor_token`. Reported symptom: MiuMjau, 373 email clicks → 7
identified customers.

**Decision (Erkki, 2026-07-17):** capture `smaily_vt` server-side, in the
existing consent-independent attribution path
(`Integrations\WooCommerce\LandingCapture`), not client-side. Rejected the
engine team's proposed sessionStorage-stash alternative — still client-side,
so it still loses a visitor who navigates before consenting; a client-side
mechanism cannot close a client-side race.

**Finding (code audit before implementing, PRO-1388):** `LandingCapture`
already captures `smaily_vt` → the `smaily_rec_uid` cookie (name/TTL from
the same engine-config keys, `tracking_cookie_name`/`cookie_ttl_days`,
`StorefrontBeacon::beacon_config()` uses for the JS `cookieNames.visitor`/
`cookieTtlDays.visitor`) as of F3-46 (`211395a`, 2026-06-26, shipped in
v3.1.0+) — on `template_redirect`, gated only on `is_connected()` +
`smaily_connect_capture_attribution`, never on consent.
`RecEngineClient.enrich()` reads whatever value sits in that cookie at
`track()`-time, independent of whether/when `captureUrlParams()` ran — so
once `LandingCapture` has written it on the landing request, a consent grant
on any later page still produces browse events carrying
`smaily_visitor_token`. This exact scenario (cookie present via a
server-side write, not via `captureUrlParams()`) is already asserted by
`LandingCaptureTest::test_resolve_captures_a_valid_visitor_token` /
`test_captures_full_link_into_config_named_cookies` (unit + integration) and
`rec-engine-client.test.ts`'s `'carries smaily_visitor_token from the
visitor cookie when present'`. **No plugin code change was required** —
Erkki's decision was already implemented as part of F3-46's broader
attribution capture, before this specific race had a name.

**Open question RESOLVED 2026-07-17:** why MiuMjau's measured 373→7
conversion stayed low despite the fix being live since v3.1.0. Of the two
possibilities named in the original audit, (b) is confirmed: a live probe of
a `?smaily_vt=…` landing on MiuMjau returned no `Set-Cookie` at all — the
store serves that page from a full-page cache, so `template_redirect` (and
`LandingCapture`) never ran on the request. See the decision below.

**JS side originally left unchanged, then revised same-day:** at first
`captureUrlParams()` in `beacon-core.ts` / `rec-engine-client.ts` was kept as
a harmless duplicate best-effort capture, gated on consent like the rest of
the beacon. Live-probing the open question above (why MiuMjau's 373→7 stayed
low despite the server-side fix being live since v3.1.0) found the answer:
**MiuMjau serves storefront pages from a full-page cache**, so on most
landing hits PHP never runs at all — `template_redirect` doesn't fire,
`LandingCapture` never executes, and the consent-gated JS capture was the
only thing that could still see the URL param, but it was waiting on a
consent decision the visitor might make on a different page (or never). A
live probe of a `?smaily_vt=…` landing on MiuMjau confirmed no `Set-Cookie`
at all.

**Decision (Erkki, 2026-07-17, evolves the decision above):** make the
browser-side URL-param → attribution-cookie promotion consent-INDEPENDENT
too — the same class of write `LandingCapture` already does server-side.
`RecEngineClient.captureUrlParams()` (visitor/rec_id/context cookies from
`smaily_vt`/`smaily_rec`/`smaily_ctx`) now runs unconditionally, and
`beacon-core.ts`'s `init()` calls it before consent is resolved rather than
inside the consent-gated `start()`. Scope is deliberately narrow: this method
writes ONLY the three attribution cookies and never creates the anonymous
SESSION cookie and never sends anything — `ensureSession()`, `track()`,
`flush()`, and cart-listener attachment stay exactly as consent-gated as
before. Rationale: a full-page cache is not a MiuMjau-only risk (any cached
storefront is exposed the same way), and the attribution cookies are the same
first-party, non-PII class F3-46 already decided is consent-independent —
extending that decision to the browser closes the last gap in the same
reasoning rather than opening a new one. `LandingCapture` stays as-is
(defense in depth for JS-disabled visitors on a cache-miss hit).

**Relationships:** extends F3-46 (server-side landing capture, now mirrored
browser-side) and F3-49 (browse events carry `smaily_visitor_token` for
cold-start, not attribution) — supersedes neither.

### PRO-1389 — Ongoing-session browse identity: server-side email injection in the `/relay` proxy (ADDENDUM to F3-49, design approved by Erkki 2026-07-21)

**Context:** `IdentityHookHandler` only merges identity on `wp_login` (§7 —
binds pre-login anon history to a customer at the moment of login). A
customer who stays logged in for a long browsing session generates no
`wp_login` event, so nothing attaches their identity to the browse events
their session produces — `StorefrontBeacon`'s own docblock flagged this as a
deferred enhancement: "server-side email injection (in the proxy, from the
auth cookie) is a later enhancement."

**Decision:** `BeaconEndpoint::attach_logged_in_identity()` resolves the
current visitor server-side, in the `/relay` proxy, and attaches
`customer_email` (contract §6, "Identity hint (if user is logged in)") to
every event in the batch actually forwarded — after the abuse/rate-limit
filtering, before the D6 send.

1. **Resolution is a cookie validation, not a REST nonce.** WP's REST
   cookie-auth only populates the current user when a valid `X-WP-Nonce`
   accompanies the request; the beacon sends none, and a page-embedded nonce
   would be stale/shared under full-page caching (the MiuMjau reality,
   PRO-1388). `resolve_logged_in_email()` instead calls
   `wp_validate_auth_cookie( '', 'logged_in' )` directly against the real
   `logged_in`-scheme auth cookie, then `get_userdata()`. An
   anonymous/invalid/expired cookie resolves to `''` — never an error.
2. **This is the one sanctioned server-side exception to F3-49's
   client-side data-minimization — F3-49 is NOT reversed.** The client
   (`enrich()`) still never sends `customer_email`/`smaily_rec_id`/
   `smaily_ctx` on browse events; that discipline is unchanged. Only the
   PROXY, server-side, on the outbound engine request, now injects the
   email for a resolved logged-in session. The JS blob (`StorefrontBeacon`
   config) and the `/relay` response never carry it.
3. **Consent does not weaken.** Event EXISTENCE is still gated solely by
   the JS marketing-consent gate (unchanged — `StorefrontBeacon`/
   `beacon-core.ts`). Injection ADDITIONALLY checks the (a).1
   `ProfilingConsent` gate for the resolved email before attaching it: an
   opted-out contact's event is forwarded **unchanged, still anonymous** —
   never dropped. The pre-existing `filter_by_profiling()` second gate
   (which drops an event carrying an opted-out email) stays as defense in
   depth; since injection itself never attaches an email for an opted-out
   contact, that drop path is never triggered by this feature.
4. **Performance:** one `wp_validate_auth_cookie()` (in-memory HMAC check)
   + a cached `ProfilingConsent::may_profile()` read (1-day transient +
   durable opt-out registry, PRO-1194) per `/relay` POST from a logged-in
   visitor — no new remote call per event.

**Rationale:** the engine already resolves `customer_email` → `customer_id`
at ingest (§6 identity-resolution flow) and retroactively binds same-session
events — the plugin was simply never sending the hint for an ongoing
logged-in session. Server-side cookie validation is the only mechanism that
survives full-page caching, matching the F3-46/PRO-1388 precedent (server-
side capture as the robust path; client-side as best-effort/defense in
depth).

**Alternatives rejected:** a page-embedded REST nonce (breaks under
full-page caching, the exact failure class PRO-1388 diagnosed); relying on
`wp_get_current_user()` via the REST cookie-auth pipeline (requires the
nonce the beacon deliberately doesn't send); sending the email from the JS
blob (would expose it in page source, violating the explicit
`StorefrontBeacon` identity note).

**GDPR docs:** `docs/DATA_MODEL_GDPR.md` and the mirrored privacy-policy
template in `docs/site/index.html` (EN+ET) previously described browse
activity as linked only to "a pseudonymous visitor identifier" — updated in
the same commit to note that a logged-in, non-opted-out session is
additionally linked directly to the account.

**Relationships:** ADDENDUM to F3-49 (client-side data-minimization —
unchanged, this is the one sanctioned server-side exception); F3-31
(`ProfilingConsent` — the (a).1 gate this reuses, no new caching built);
F3-27 (`IdentityHookHandler` — the `wp_login` binding this complements, not
replaces); F3-46/PRO-1388 (server-side-survives-caching precedent).

### PRO-1402 — Multilingual stand-in rows: `external_id` deliberately stays the translation post's own id (engine decision, Erkki-approved 2026-07-21)

**Decision: no change.** On a multilingual stand-in catalog row (default-language
canonical post trashed/draft, a published translation "stands in"), `sku` is
canonicalized to `woo-<canonical_id>` but `external_id` remains the translation
post's OWN id — so the two ids differ on exactly that row shape, and that is
intended semantics, not a bug. Engine rationale (PRO-1329 thread, 2026-07-21):
`sku` is the stable identity/join key; `external_id` is "the raw platform id
that resolves to a live product right now", which is what the storefront
recommendations endpoint needs for render-time resolution and add-to-cart —
aligning it to the canonical id would point at a trashed/draft post in
precisely the case where it matters. The storefront RFC records the same
semantics engine-side (`external_id` = id of the unit actually serialized;
may differ from the id inside `sku` on stand-in rows). Do NOT "fix" this
divergence in a future pass; a change here is a contract question for the
engine first (`CatalogPayloadBuilder` `external_id` vs `SkuResolver`
canonical id).

### PRO-1498 — `catalog.delete` tombstones are ALWAYS force-filled and sent, never silently skipped

**Context:** F3-39/F3-40 skip a captured removal object whose `category_path`
or `product_url` comes back blank, on the theory that a never-published
artifact (auto-draft GC burst) was never synced, so there's nothing to remove.
MiuMjau live evidence (2026-07-21) showed the same skip firing for a genuinely
*synced* product — 51 rows failing engine validation with empty `product_url`,
+1 with empty `category_path` — which is a different case: the SKU already
exists in the engine, so silently skipping (or the engine rejecting) the
removal leaves it stuck `in_stock=true` forever (the engine has no
delete-by-key; a soft removal is the ONLY way to mark a SKU unavailable,
contract §3).

**Decision:** a catalog.delete tombstone must ALWAYS reach the engine.
`CatalogPayloadBuilder::ensure_valid_removal()` force-fills a still-blank
`category_path`/`product_url` with a generic, honest placeholder
(`'uncategorized'`; a synthetic `home_url('/?smaily_connect_removed_product=
{id}')` URL) instead of leaving it blank or skipping the row.
`CatalogPayloadBuilder::build_unresolvable()` covers the deeper case — a
product/variation id that no longer resolves to a `WC_Product` AT ALL (e.g. its
`product_type` came from a since-deactivated plugin) — building a whole minimal
tombstone from the bare id. `CatalogHookHandler::is_removable()` (the old
skip-gate) is retired; `enqueue_delete()` / `CatalogBackfillJob::
enqueue_unavailable()` now always enqueue. `SkuResolver::resolve_id()` (new,
mirrors `resolve_order_item()`'s F3-43 `woo-oi-{item_id}` fallback) canonicalizes
a bare id without needing a loadable product.

**Rationale:** delete-only, deliberately. The live `catalog.upsert` path keeps
failing loud on the same gap (`primary_category_path()`'s "even the store
default is unresolvable" residual case) — there, an empty required field is
still a genuine merchant-data-gap signal worth surfacing (F3-39's original
intent). A tombstone protects no such signal — its only job is "mark this SKU
unavailable" — so correctness requires it always reach the engine, mirroring
F3-43's order-item never-drop principle (a line that can't be keyed still
ingests under a synthetic key rather than vanishing). A hardcoded placeholder
string/URL is honest here because the row is never shown or clicked
(`in_stock=false`, delisted) — its only meaning is "no longer available."

**Alternatives rejected:** keep skipping (rejected — the exact stale-row bug
this closes); reject the row and mark it permanently failed (rejected — same
stuck-`in_stock=true` outcome, just with engine-visible noise instead of
silence); invent a plausible-looking real product URL, e.g. the historical
`product_base_url + sku` shape (rejected — contract explicitly calls this out
as misleading for a *live* product, F3-17; a clearly-synthetic marker avoids
that concern for a tombstone too).

**Scope boundary:** §3b `catalog.remove` (PRO-1230, a hard-deleted PARENT's
product-level tombstone) is a DIFFERENT mechanism and is untouched by this
decision — its own `get_product() === null` branch in
`on_hard_delete_product()` still no-ops, unchanged.

**Mock:** `tests/Integration/Fixtures/mock-rec-engine/router.php` now rejects
an empty `product_url` the same way it already rejected `category_path`
(PRO-1491/e98e092) — folds in PRO-1492's mock-divergence finding.

**Follow-up (not this change):** MiuMjau's existing stuck rows were captured
under the old blank shape before this fix shipped — the code fix does not
retroactively repair them; they need a post-release re-drive (re-touch the
affected products, or a targeted re-backfill) so the fixed builder re-captures
a valid object.

**Relationships:** F3-39 (the original skip-gate + the store-default-category
fallback this fix does NOT touch on the upsert side), F3-40 (trash → soft
`in_stock=false`, the mechanism this hardens), F3-43 (the order-item
never-drop principle this extends to catalog), PRO-1224 (`SkuResolver` —
`resolve_id()` mirrors its `woo-` canonicalization for a bare id), PRO-1491/
PRO-1492 (the category_path mock-strictness precedent this mirrors for
`product_url`).

### PRO-1486 — `customer_email` stripped from client-supplied `/relay` browse events (engine-confirmed via PRO-1490)

**Context:** `BeaconEndpoint::EVENT_FIELDS` — the per-event whitelist
`validate_batch()` applies to a client-supplied browse-event POST — included
`customer_email` with no check on its origin. Since it is client-controlled
input, any caller of the public `/relay` route could attach an arbitrary email
to otherwise-anonymous browsing (spoofed attribution/personalization signal)
or use the endpoint as an oracle to probe whether a guessed email has opted
out of profiling (the `filter_by_profiling()` (a).1 gate's drop-vs-keep
behavior leaked that bit per guess, with no rate-limit-independent cost to an
attacker beyond the existing per-IP/session throttle). No legitimate producer
ever sent it client-side: the JS client (`rec-engine-client.ts` `enrich()`)
never emits `customer_email` (F3-49), and the one sanctioned source is
`BeaconEndpoint::attach_logged_in_identity()` (PRO-1389), which resolves it
server-side from the real `logged_in` auth cookie and attaches it AFTER
`validate_batch()` runs — entirely independent of the whitelist.

**Decision:** `customer_email` is removed from `EVENT_FIELDS`. A
client-supplied `customer_email` on the `/relay` POST body is now silently
dropped by the whitelist, identically to any other unrecognized field —
before `attach_logged_in_identity()` or `filter_by_profiling()` ever run, so
injection semantics (server-resolved email only, opted-out ⇒ forwarded
anonymous not dropped) are completely unchanged. Confirmed with the engine
team (PRO-1490) before shipping: no legitimate producer sends it client-side,
the contract already supports senders omitting identity hints entirely, and
nothing engine-side depends on receiving a client-originated `customer_email`.

**Scope caveat (engine team, honored in code):** the strip applies to the
BROWSE-EVENT POST path only (`BeaconEndpoint::EVENT_FIELDS` /
`validate_batch()`), not to `/relay` as a route in the abstract. `/relay`
today handles only this one POST shape. If a future storefront-
recommendations GET proxy is added to this route (or a sibling route) that
legitimately takes a `customer_email` query param (e.g. "recommendations for
this known customer"), that handler must NOT reuse `EVENT_FIELDS`/
`validate_batch()` unmodified — it needs its own explicit field handling. A
code comment on `EVENT_FIELDS` and the class docblock's abuse-model section
both point here.

**Dead-code cleanup:** `filter_by_profiling()`'s per-event branch that
re-checked a client-supplied email DIFFERING from the server-resolved one
(`may_profile()` called again per non-matching email) is now unreachable — a
differing client-supplied email can no longer exist once `validate_batch()`
strips it before either method runs. That branch is removed (was: a ternary
falling back to a fresh `may_profile()` call; now: a direct use of the
already-computed `$verified_allowed`). The surrounding loop/drop-counter/
logging structure is KEPT as defense-in-depth — cheap, and it protects
against a hypothetical future `customer_email` producer that attaches the
field without its own consent check — even though, in the current
single-producer graph (`attach_logged_in_identity()` is the only source, and
it already gates on `may_profile()` before attaching), that drop path cannot
actually trigger today. `attach_logged_in_identity()`'s return shape also
dropped the now-unused `verified_email` key (only `verified_allowed` is
consumed after the simplification).

**Not done in this pass (follow-up — CLOSED by PRO-1712, 2026-08-07):**
`smaily_rec_id` and `smaily_ctx` remained in `EVENT_FIELDS` and were
client-suppliable on a browse event, contrary to F3-49's client-side-omission
intent, via the same whitelist-pass-through mechanism this decision closed for
`customer_email`. The JS client never sent them, so there was no known live
exploitation, but the whitelist itself did not enforce that. The follow-up
evaluation concluded the same spoofing logic applies and closed it the same
way — see PRO-1712 below.

**Tests:** unit (`BeaconEndpointTest::test_client_supplied_customer_email_is_stripped`,
`BeaconEndpointIdentityTest::test_client_supplied_customer_email_is_stripped_and_never_checked`)
prove the whitelist strip and that no profiling lookup occurs for a stripped
value; integration
(`RecEngineBrowseProxyTest::test_client_supplied_customer_email_is_stripped_and_not_used_for_profiling`)
proves a spoofed email — including one matching a real opted-out contact —
never reaches the mock engine and never causes a drop, over the real `/relay`
POST path.

**Relationships:** F3-49 (client-side data-minimization — this decision adds
the matching SERVER-side enforcement for `customer_email` specifically);
PRO-1389 (the sole surviving source of `customer_email` on a forwarded event,
unchanged by this decision); PRO-1490 (engine-side confirmation this shipped
against).

### PRO-1499 — `tags.category_defaulted` marks a substituted (placeholder) `category_path`, so the engine skips slug-derivation for it

**Context:** contract v1.6.0 (engine commit `06266a8`, engine-side skip logic
already deployed per PRO-1500) adds an optional catalog tag
`tags.category_defaulted` (`"true"`, omit-on-false): a signal that a row's
`category_path` is a **placeholder substituted by the sender**, not real
product taxonomy — the store default-category fallback (F3-39/PRO-1491's
empty-terms branch) or a `catalog.delete` tombstone that has to sync with
*some* category (PRO-1498's `ensure_valid_removal()`/`build_unresolvable()`).
Without the flag the engine's `mapRawAttributes()` derives
species/`category_canonical`/replenishable from the category **slug** as if
it were real taxonomy — on a placeholder row that derivation is noise (a
generic "uncategorized"/store-default term slug carries no product signal).

**Decision:** `CatalogPayloadBuilder` stamps `tags.category_defaulted = "true"`
on exactly the rows where a placeholder was substituted, and omits the key
everywhere else (never `"false"`):
- `build()` — `primary_category_path()` gained an optional by-ref `$defaulted`
  out-param, set `true` only on the empty-terms → store-default-fallback
  branch (PRO-1491). `tags()` stamps the flag only when BOTH `$defaulted` is
  true AND the resulting `category_path` is non-empty — an unresolvable store
  default (category_path stays `""`) is not a substituted *value*, and that
  row fails the engine's REQUIRED-field check regardless, so there is nothing
  to flag.
- `ensure_valid_removal()` — stamps the flag exactly when it force-fills a
  still-blank `category_path` with `PLACEHOLDER_CATEGORY` (the PRO-1498 path).
  A row whose `category_path` `build()` already resolved (real terms, or a
  non-empty store-default already flagged by `tags()`) is left untouched here.
- `build_unresolvable()` — ALWAYS carries the flag (unconditionally, in the
  return array literal): there is no real product to derive a category from,
  so every field including `category_path` is definitionally a placeholder.

**Rationale:** the flag's value is only meaningful when it corresponds to an
actual placeholder STRING reaching the engine — flagging an already-doomed
empty-string row would be dishonest (nothing was actually substituted; the
row never becomes a real catalog entry) and untestable without inventing
behavior the contract doesn't ask for. Gating on "both defaulted AND
non-empty" keeps the semantics exactly what the contract prose says: "the
sender substituted the store's fallback/default category."

**Mock:** no change needed. `tests/Integration/Fixtures/mock-rec-engine/
router.php` already captures the whole `tags` object generically per-SKU
(`last_catalog_tags`, added for PRO-1224's `tags.product_id`) with no
allowlist on tags keys — it already accepts (and lets a test introspect) an
arbitrary new tags key without modification.

**§6 customer_email deprecation (same contract sync, verified no-op for us):**
the contract's v1.6.0 §6 deprecation notice for client-originated
`customer_email` on browse events matches what PRO-1486 already shipped
(2026-07-21, same day) — `BeaconEndpoint::EVENT_FIELDS` already strips a
client-supplied `customer_email` before it ever reaches profiling/injection
logic, and the sole surviving source (`attach_logged_in_identity()`,
PRO-1389, server-side) matches the contract's carved-out "server-side
senders... may continue sending it" exception. No further code change was
needed or made for this contract sync.

**Tests:** unit (`CatalogPayloadBuilderTest`) — the store-default-fallback
upsert row carries the flag; the unresolvable-default (empty `category_path`)
row does NOT; `ensure_valid_removal()`'s force-fill path carries it, its
product_url-only force-fill does NOT; both `build_unresolvable()` cases
(placeholder literal and resolved store-default) always carry it; the
existing real-category test proves omission via its unchanged exact-array
assertion. Integration (`RecEngineCatalogTest`) — the PRO-1491 no-term-product
upsert test and the PRO-1498 force-filled-removal test now also assert the
flag reached the wire via `last_catalog_tags` mock introspection.

**Live-walk:** `bin/walk-pro1499-category-defaulted.cjs` proves the real
sandbox engine ("Smaily Connect test" tenant) accepts a no-product_cat-term
product's catalog.upsert carrying `tags.category_defaulted:"true"`
(`processed:1, sent:1, failed:0`, `{"http":200,"outcome":"accepted"}`) — run
2026-07-21, `RECENGINE_LIVE=1 node bin/walk-pro1499-category-defaulted.cjs`.

**Relationships:** F3-39/PRO-1491 (the store-default-category fallback this
flags), PRO-1498 (the delete-tombstone force-fill paths this flags), PRO-1224
(`tags.product_id` — the prior precedent for the mock's generic tags capture
needing no schema change), PRO-1486 (the §6 customer_email deprecation this
sync also carried, independently verified as already covered).

### PRO-1506 — `catalog.delete` force-fill also runs at FLUSH time, so a pre-3.8.1 stuck row heals on Retry

**Context:** PRO-1498's `ensure_valid_removal()`/`build_unresolvable()` run
ONLY at enqueue time (`CatalogHookHandler::enqueue_delete()`/
`enqueue_delete_unresolvable()`, `CatalogBackfillJob`). `IngestFlusher::
row_to_object()` sends a `catalog.delete` row's STORED captured object
verbatim (only stamping `event_id`/`in_stock=false`) — it never re-derives it.
Confirmed live on MiuMjau (2026-07-21, post-3.8.1): re-driving the 52 rows
stuck under the OLD (pre-fix) blank shape via Event Log Retry failed again
with the identical errors, because the flusher resent the same stored blank
`category_path`/`product_url` unchanged. PRO-1498 prevents new stuck rows but
cannot heal old ones — exactly the "Follow-up" gap that decision's entry
flagged (a targeted re-drive was assumed to be enough; it isn't, because the
re-drive path itself didn't repair anything).

**Decision:** `IngestFlusher::row_to_object()`'s `catalog.delete` branch now
also runs the stored object through `CatalogPayloadBuilder::
ensure_valid_removal()` before stamping `event_id`/`in_stock`, and falls back
to `build_unresolvable( entity_id, event_uuid )` when the row carries no
captured object at all (a corrupt/missing payload) instead of a terminal skip
with nothing sent. Both are idempotent/safe to run unconditionally: on an
already-valid (post-3.8.1) capture they're a no-op (the fields are already
non-blank), so this doesn't change enqueue-time behaviour or double-flag an
already-flagged row.

**Rationale:** single chokepoint, minimum surface — the two builder methods
already exist and already encode "what does a sendable tombstone look like";
the bug is purely that the flush path never called them. Fixing it there (not
by re-deriving at enqueue and re-writing every stuck row in the DB) also heals
FUTURE stuck rows automatically, not just today's 52 — any future edge case
that slips a blank required field into a stored capture self-heals on its next
Retry/backoff tick, not just a one-time manual fix.

**Consequence for the mock-parity test:** `RecEngineCatalogTest::
test_mock_rejects_empty_product_url_on_a_delete_row_like_the_live_engine`
used to enqueue a `catalog.delete` row with a blank `product_url` and assert
the FLUSHER's send got rejected — that path can no longer reach the mock with
a blank value (the flusher repairs it first), so the test now posts the raw
blank payload directly through `Client::ingest_catalog()` instead, keeping the
mock/live parity assertion without depending on now-superseded flusher
behaviour.

**Tests:** unit (`IngestFlusherTest`) — a stored row with blank
`category_path`/`product_url` is repaired (force-filled + `tags.
category_defaulted` stamped) and sent, not skipped/failed; a row with no
captured `object` at all falls back to `build_unresolvable()` and is sent, not
terminal-skipped. Integration (`RecEngineCatalogTest`) — a directly-enqueued
row simulating the pre-3.8.1 stored-blank shape drains successfully on flush
(`sent:1, failed:0`, `tags.category_defaulted` reaches the engine).

**Follow-up (not this change, operational):** MiuMjau's 52 already-stuck rows
still need a live Retry (or a fresh re-drive) once this ships — the code fix
makes the NEXT retry succeed; it doesn't retroactively re-send anything by
itself.

**Relationships:** PRO-1498 (the enqueue-time force-fill this extends to
flush time — same two builder methods, new call site), PRO-1499
(`tags.category_defaulted` — the flush-time force-fill stamps the same flag,
consistent with the enqueue-time one), F3-43 (the order-item never-drop
principle both PRO-1498 and this decision extend to catalog).

### PRO-1504 — Transactional emails, Stage 1: config-only surface (Option B — a separate bound Smaily account), no send path (design approved by Erkki 2026-07-22)

**Context:** Erkki wants order-confirmation and shipping-confirmation emails
sent through Smaily instead of (eventually replacing) WooCommerce's native
transactional emails. Two designs were on the table: Option A (route through
the SAME Smaily account/workflows already used for marketing) vs Option B (a
SEPARATE Smaily account bound purely for transactional sends, isolated from
marketing deliverability/reputation). Erkki approved **Option B**. Building
the sender, the native-WC-email suppression, and the fail-open fallback in
one pass would be a large one-way-doorish surface (customer-facing email
delivery) landing without a checkpoint — so the work is split: **Stage 1**
(this decision) is pure configuration, **zero behavior change**; the sender
is a later, separately-approved stage.

**Decision:** Stage 1 ships:
1. A second Smaily account bound under `Settings\Credentials` account_key
   `'transactional'` (the SAME multi-account mechanism Mode A per-language
   accounts already use — `smly_plus_credentials_transactional`, no new
   storage class). An enablement toggle (`smly_plus_transactional_emails_
   enabled`, default OFF) gates whether the section's fields even render.
2. Two new automation-mapping trigger types, `order_confirmation` and
   `shipping_confirmation`, stored as ordinary rows in the EXISTING
   `smly_plus_automation_mapping` table (migration 003 — `(trigger_type,
   language, account_key, workflow_id, is_default_fallback)` already fits;
   no schema change) with `account_key='transactional'`. The mapping UI
   (`AutomationSection`) gained one prop, `accountKeyOverride`, so it always
   renders a single row pinned to the transactional account instead of
   deriving the row shape from the site's multilingual mode — this account
   has no per-language variant in stage 1.
3. A new setting, `smly_plus_shipped_order_statuses` (array of bare WC
   status slugs, default `['completed']`), populated from
   `wc_get_order_statuses()` (incl. custom-registered statuses) via a new
   `EnvDetector::order_statuses()` env field. Setting only — no order-status
   change listener exists yet.
4. `SettingsEndpoint::replace_automation_mappings()` gained an explicit
   `VALID_TRIGGER_TYPES` allowlist (welcome/first_order/abandoned_cart/
   order_confirmation/shipping_confirmation) — previously any string reached
   an INSERT via a bare `sanitize_key()`. Extending it (rather than adding a
   parallel check) is also a small defense-in-depth fix on the pre-existing
   three triggers.

**What stage 1 deliberately does NOT do:** no send path, no WC email
suppression, no order/shipment hook binding, no `AutomationRouter::
trigger_automation()` call for either new trigger type (confirmed —
`WorkflowResolverInterface`'s docblock now notes the two new trigger_type
values exist in the mapping table but no caller passes them yet). With the
enablement toggle off (default), nothing new renders and no new option
differs from its pre-stage-1 absence — the plugin's behavior toward
customers is unchanged whether or not this ships in a release.

**Rationale (why config-first, not sender-first):** the sender + WC-email
suppression + fail-open fallback is the one-way-door part (a customer-facing
delivery change, CLAUDE.md's interrupt trigger) — building it without a
checkpoint would mean writing untested-in-production send logic before Erkki
has seen the account-binding/mapping UX at all. Stage 1 is fully reversible
(pure config, unreleased-safe) and lets the mapping UI get checkpointed
before the higher-stakes stage lands. Reusing `Credentials`' existing
multi-account mechanism and the existing mapping table means stage 2 is
"wire a new call site," not "invent new storage."

**Alternatives considered:** Option A (reuse the default/marketing account)
was rejected by Erkki specifically to keep transactional deliverability
reputation isolated from marketing sends — a bounce/complaint storm on a
campaign shouldn't threaten order-confirmation delivery, and vice versa.

**Tests:** unit — `EnvDetectorTest` (new `orderStatuses` snapshot field,
bare-slug stripping of the `wc-` prefix, `transactionalCredentials`/
`transactionalConnected`/toggle defaults + password-omitted read-back),
`SettingsEndpointTest` (transactional account persists with the same
empty-password-preserves-existing rule as the default account, the verified
flag tracks credential completeness not the enablement toggle, the two new
trigger types persist, an unknown trigger_type is dropped before any
INSERT). Integration — `SettingsRoundTripTest::
test_woocommerce_tab_round_trip_including_transactional_emails` (writer/
reader key symmetry, the class of bug this file exists to catch per its
header note). Component — `TransactionalEmailsSection.test.tsx` (off =
nothing beyond the toggle renders and no `/workflows` fetch fires; on =
credential block + both trigger sections appear and their dropdowns fetch
the `'transactional'` account_key, never `'default'`; the shipped-status
checkboxes reflect `env.orderStatuses`).

**Relationships:** reuses the Settings\Credentials multi-account mechanism
(sub-PR 5.A) and the `smly_plus_automation_mapping` table (migration 003)
wholesale — no new storage primitive. `AutomationSection`'s
`accountKeyOverride` prop is the one structural addition to a shared
component; stage 2 (the sender) is the natural next decision entry when it
lands.

### PRO-1504 — Transactional emails, Stage 2: the sender, native-email suppression, fail-open fallback (design approved by Erkki 2026-07-22)

**Context:** Stage 1 (above) built the config surface with zero behavior
change. Stage 2 is the one-way-door part CLAUDE.md's interrupt rule exists
for — a customer-facing email delivery change — so every design point below
was fixed by Erkki in the task brief before any code landed; this entry
records the decisions made, not a re-derivation.

**Decision — six pieces, one gate:**

1. **Triggers.** Order confirmation fires on `woocommerce_checkout_order_
   processed` (one-shot; deliberately NOT `woocommerce_thank_you`, which
   re-fires on every thank-you-page revisit). Shipping confirmation fires on
   `woocommerce_order_status_changed` when the bare-slug new status is in
   the merchant's `smly_plus_shipped_order_statuses` set. Both are
   once-per-order-per-email-type via an order-meta guard
   (`_smly_plus_transactional_{type}_status`) checked before the gate even
   runs — cheap, and survives a repeated flip into the shipped set.

2. **Gating — `TransactionalGate`.** A send happens only when ALL hold:
   the master toggle, that trigger's own toggle, a mapping row resolves
   (`WorkflowResolverInterface::resolve_workflow($trigger, null)` —
   language is always `null`, this account has no per-language variant),
   and the mapped account's credentials are complete. Deliberately NO
   consent/opt-out gate (platform answer Q7, PRO-1380) — transactional
   sends override marketing opt-out on purpose. The SAME gate object
   backs both the WC hook handler's decision to send and the suppression
   filters' decision to suppress, so the two can never disagree about
   whether the feature is "on" right now.

3. **Send path — `Client::send_message()` + `TransactionalFlusher`.**
   `POST /api/message/send.php` on the transactional account's subdomain,
   JSON body `{autoresponder_id, to:[email], context}` — the one Client
   method that isn't form-encoded (a new `$json` flag on the private
   `request()` helper). Success = HTTP 200 + body `{code:101}`; ANY other
   body code is TERMINAL (203/221 are the two documented ones, but the rule
   is generic: a deterministic Smaily-side rejection never retries, matching
   the CartFlusher/legacy-API precedent of "non-101 = terminal"); network
   errors/5xx/429 throw `ApiException` = TRANSIENT. `TransactionalFlusher`
   is ONE dispatcher for both paths: `send_now()` (called synchronously from
   the WC hook) ALWAYS enqueues the row first, then dispatches it inline —
   so a successful sync send still lands in the Event Log (F3-44), not just
   failures. A transient failure leaves the row `pending`; the flusher's own
   AS action (`smly_plus_flush_transactional_events`, its own event types
   `transactional.order_confirmation`/`transactional.shipping_confirmation`)
   retries it later. No bounded-attempts ceiling was added — the shared
   Smaily `EventQueue` (unlike the rec-engine `IngestQueue`) has never had
   one; CartFlusher/the main Flusher retry transient failures indefinitely
   too, and a stuck row is still visible + manually retryable in the Event
   Log. Adding a new ceiling mechanism here would have been scope the task
   didn't ask for.

4. **`context` payload — `TransactionalPayloadBuilder`.** Template parity
   with the abandoned-cart merge-tag shape (CartPayloadBuilder): the SAME
   `product_<field>_1..10` + `over_10_products` matrix, plus order-level
   extras (order_number, order_total, currency, payment_method,
   shipping_method, first/last name). Sourced from the frozen
   `WC_Order_Item_Product` snapshot (survives a since-deleted product,
   unlike CartPayloadBuilder which reads a live `wc_get_product()`) with
   gross per-unit pricing — `get_total()+get_total_tax()` ÷ qty (PRO-1241
   basis), not the product's live/current price, since a confirmation email
   must show what the customer actually paid.

5. **Native-email suppression — `TransactionalSuppression`.** Suppresses
   `woocommerce_email_enabled_customer_processing_order` and `_customer_
   completed_order` ONLY while `TransactionalGate::resolve_if_open()` holds
   for that trigger — never admin emails. Completed-order suppression has
   one MORE condition: `'completed'` must itself be in the merchant's
   shipped-status set, because a custom shipped status (e.g. "Shipped") has
   no native WC email to begin with — suppressing it would just delete a
   confirmation with nothing replacing it for that status.

6. **Fail-open (Erkki decision 2026-07-22).** A TERMINAL failure — on the
   sync attempt OR a later queued retry reaching a terminal Smaily response
   — re-fires the corresponding native WC email, bypassing
   `TransactionalSuppression` for that ONE call via a request-scoped static
   flag the suppression filters check first. Guarded by the SAME order-meta
   value (`failed_open`) so a manually-retried failed row (Event Log
   "Retry") can't double-fire the native email. When no native email was
   ever suppressed for that trigger (the custom-shipped-status case),
   fail-open just leaves the `mark_failed` queue row as the incident record
   — there's nothing to re-fire.

**Rationale:** every one of the six pieces above was specified in the task
brief, not derived here — recording WHY each holds (not just what) so a
future change doesn't accidentally re-open the one-way door without
re-checking the reasoning. The "always enqueue, even on sync success"
choice in particular is what makes the Event Log a complete record instead
of only showing failures — a deliberate trade of one extra DB row per send
for full observability, consistent with F3-44's original intent.

**Alternatives considered:** a bounded-retry ceiling on the transactional
queue rows (mirroring the rec-engine `IngestQueue`'s `max_attempts`) was
considered and rejected — the Smaily-marketing `EventQueue` this reuses has
never had one, and inventing a new retry-ceiling mechanism for one event
type would be an inconsistency, not a fix; "queue retries exhausted" in the
design brief is satisfied by a TERMINAL response reached during a retry
(no more retrying to do), not by a manufactured attempt count.
**Superseded by PRO-1519 below** — this reasoning missed that a run of
purely TRANSIENT failures never reaches a terminal response at all, so the
fail-open path this whole decision exists for could never trigger on that
class of failure. A ceiling was added, scoped to this class only.

**Tests:** unit — `ClientTest` (+3, `send_message()` JSON body/headers/
5xx), `TransactionalPayloadBuilderTest` (order-level fields, gross pricing,
deleted-product-line survival via the frozen snapshot, the 10-slot matrix +
`over_10_products` overflow), `TransactionalGateTest` (all four conditions
independently, `language=null` on the resolver call), `TransactionalSuppressionTest`
(suppress-only-while-open, the extra completed-order shipped-status
condition, the bypass mechanic), `TransactionalFlusherTest` (success/
terminal/transient/fail-open/meta-guard/queue-scoping), `TransactionalEmailHookHandlerTest`
(gate-closed no-op, meta guard, `wc-` prefix normalisation, repeated-flip
no-resend). Integration — `TransactionalEmailsPipelineTest` (order
confirmation end-to-end against a mocked `message/send.php`; shipping
confirmation once + no-resend on a flip-away-then-back; suppression toggles
live with the master switch; everything-off is a verified no-op; a terminal
203 marks the row failed + sets the fail-open meta; a 5xx lands the row
`pending`, the main flusher's hook leaves it untouched, the dedicated hook
drains it).

**Relationships:** extends Stage 1's config surface (account, toggles,
mapping rows, shipped-status set) with zero storage changes — no new table,
no schema migration. `Flusher::flush()`'s exclude-list grew from one entry
(`CartFlusher::EVENT_TYPE`) to three; any FUTURE new Smaily-EventQueue event
type must make the same deliberate choice CLAUDE.md calls out.

**Addendum — PRO-1518 (2026-07-22): order confirmation also needs the
Store-API twin.** Point 1 above wired order confirmation to
`woocommerce_checkout_order_processed` only, which never fires for a
WooCommerce Blocks / Store-API checkout (WC default since 8.3) — a
block-checkout store got zero order-confirmation sends. Fixed by mirroring
the exact F3-46 precedent (`HookHandler::on_block_checkout_order_processed`
on `woocommerce_store_api_checkout_order_processed`, 1-arg `$order` shape,
unlike the classic hook's 3 args):
`TransactionalEmailHookHandler::on_block_checkout_order_processed()` calls
the SAME `attempt()` the classic hook uses, so the existing
once-per-order-per-type meta guard (point 1) already makes it safe if a
store somehow fires both hooks for one order — no new guard needed.
Shipping confirmation is untouched (it doesn't hang off a checkout hook).

**Addendum — PRO-1519 (2026-07-22): bounded retry ceiling closes the
fail-open gap on a persistent transient failure.** Stage 2's own
"Alternatives considered" (above) rejected a retry ceiling on the grounds
that fail-open already fires on any TERMINAL response reached during a
retry. That reasoning has a hole: a run of purely TRANSIENT failures
(`ApiException` — broken credentials, a prolonged Smaily outage) never
produces a terminal response, so `record_attempt()` loops the Smaily
EventQueue's normal unbounded-retry-until-manual-review convention forever
— which is the right convention for marketing rows (nothing is suppressed
waiting on a `contact.sync` retry) but wrong here, because
`TransactionalSuppression` keeps the customer's native WC email suppressed
for the entire time a transactional row is pending. An unbounded retry
therefore means: outage persists → customer never gets any email, ever.

**Fix:** `TransactionalFlusher::RETRY_CEILING_SECONDS` = `HOUR_IN_SECONDS`
(3,600s), checked against the row's `created_at` at the top of `process()`
(`enforce_retry_ceiling()`) — once exceeded, throw the SAME
`TerminalDispatchException` a deterministic Smaily rejection throws, so the
row flows through the existing `mark_failed` + fail-open path with zero new
fallback logic (the task's own framing). Scoped to the two transactional
event types ONLY — `enforce_retry_ceiling()` no-ops for any other
`event_type` (defence in depth: this class only ever drains
`transactional.order_confirmation` / `transactional.shipping_confirmation`
rows via `pending()`'s type filter, but the check doesn't rely on that
alone). The main `Flusher` and `CartFlusher` (marketing-side: `contact.sync`,
`automation.*`, abandoned-cart) are untouched — no shared code path, no
option, nothing to configure differently there.

**Why time-based, not attempts-based:** the task allowed either. Time is
what the customer actually experiences (an hour of no confirmation, not "60
attempts"), and the two move together anyway — `TransactionalFlusher::
FLUSH_HOOK` runs every 60s (`Bootstrap::init_hooks()`), so 3,600s ≈ 60
retries at the steady-state cadence; picking time avoids the ceiling
silently tightening or loosening if that cadence ever changes. One hour was
chosen as long enough to ride out a brief blip (a deploy restart, a few
minutes of 5xx) without prematurely undercutting Smaily's own delivery
attempt, but short enough that the customer isn't left waiting overnight
for a confirmation.

**Not touched:** the synchronous first attempt (`send_now()`) — a
freshly-inserted row's `created_at` is "now", so it's never past the
ceiling; the sync path's own terminal/transient split is unchanged. The
`flush()` retry path is the only one this can affect, matching the task's
scope ("purely the queued-retry path").

**Tests:** unit — `TransactionalFlusherTest` (+3: a row past the ceiling
fails open without calling the API; a row still within the ceiling keeps
retrying normally — boundary sanity; a non-transactional-type row is never
force-failed by the ceiling even when ancient). Integration —
`TransactionalEmailsPipelineTest` (+2: a row stuck on repeated 5xx fails
open once its `created_at` is backdated past the ceiling and the next AS
tick runs — timestamp manipulation, not `sleep()`; a marketing-side
`contact.sync` row backdated the same way keeps the EventQueue's ordinary
unbounded-retry convention, proving the ceiling is scoped to
`TransactionalFlusher` and does not leak into the shared `Flusher`).

### PRO-1540 — Transactional emails: Settings UI restructured across two tabs (undiscoverability fix; design settled with Erkki 2026-07-23)

**Context:** v3.9.0 shipped Transactional emails as ONE combined
`TransactionalEmailsSection` card — an enablement toggle, the separate
account's credentials, the connection test, AND both trigger mappings
(order/shipping confirmation) — mounted as a fourth section at the bottom
of the WooCommerce automations tab/step. Erkki found it undiscoverable on
the pilot store: nothing in the Connection tab (where every other Smaily
account lives) hinted that a second, separate account existed, and the
feature was buried below three unrelated store-run automations.

**Decision:** Split the one section into two, matching where a merchant
would actually look:
1. **Connection tab** (`Step1Connect.tsx`) gets the account itself as an
   OPTIONAL capability under the main connection — `TransactionalEmailsSection`
   (same file/component, relocated) renders "Use transactional emails" →
   the existing `CredentialBlock` (subdomain/username/password + Test
   connection + the same green "✓ Connected" state the main account uses)
   reused verbatim, no new validation pattern invented.
2. **WooCommerce tab** (`Step3WooCommerce.tsx`) gets a NEW
   `TransactionalTriggersSection` rendering the Order-confirmation /
   Shipping-confirmation `AutomationSection`s + the shipped-status picker
   under their own subheading, styled like `EngineAutomationsSection`'s
   sub-section — gated on `state.transactionalConnection.kind ===
   'success'`. **Erkki's explicit call:** when not connected, this section
   renders NOTHING — no placeholder card, no "connect on the Connection
   tab" pointer. (Contrast with `EngineAutomationsSection`, which DOES show
   an upsell banner when its own gate is closed — deliberately different
   here per Erkki's instruction, not an oversight.)

**Wiring, not a rename:** every `smly_plus_*` option key and every REST
field name (`transactionalEmailsEnabled`, `transactionalCredentials`,
`orderConfirmationEnabled`, `shippingConfirmationEnabled`,
`shippedOrderStatuses`) is unchanged — only WHICH Settings-tab payload
carries which field moved. `action-to-tab.ts` reassigns the two account
actions (`SET_TRANSACTIONAL_EMAILS_ENABLED`, `SET_TRANSACTIONAL_CREDENTIALS`)
from the woocommerce arm to the connection arm; `buildTabPayload.ts` moves
the same two fields between the `connection`/`woocommerce` cases;
`SettingsEndpoint::save_transactional_emails()` splits into
`save_transactional_account()` (called from `save_connection()`) and
`save_transactional_triggers()` (called from `save_woocommerce()`, same
call site the combined method used). A v3.9.0 store's stored options are
read identically regardless of which handler last wrote them — no
migration needed, no data touched. `hydrate.ts`/`EnvDetector::
saved_settings()` needed zero changes: `transactionalConnected` already
fed the shared `deriveCredentialConnection()` helper the same way the main
account's `smailyConnected` does, so the Connection-tab checkmark was
already server-truth-driven before this ships.

**Copy fix (Erkki flagged in the same review):** `TransactionalEmailsSection`'s
credential-block description said "A separate Smaily account (or
sub-account)" — Smaily has no sub-account concept, and the parenthetical
reads as if it does. Changed to plain "A separate Smaily account…"
everywhere the phrase appears, including `docs/site/index.html` (which was
already correct — the plugin-code string was the only offender).

**Rationale:** this is a placement/discoverability fix, not a feature
change — Erkki was explicit the design was settled and this is
implementation, not exploration (hence no plan-first checkpoint beyond the
brief itself). Reusing `CredentialBlock` and `AutomationSection` unchanged
keeps the fix to wiring + two small new host components, not a rebuild.
The WC-tab "render nothing" choice (vs. an upsell banner) is deliberate:
Erkki judged that a placeholder pointing back to Connection would just be
a second thing to notice-and-ignore for a merchant who hasn't opted into
the feature at all, unlike Campaign Intelligence (an always-relevant
upsell) — different call, not an inconsistency to "fix" later.

**Alternatives considered:** keeping the account fields on the WooCommerce
tab and only moving/duplicating a link — rejected because the credential
block genuinely belongs next to the OTHER Smaily account (Connection tab
is where merchants already know to look for "is my Smaily account
connected"); a single merged tab — rejected, Erkki wanted the trigger
config to stay a WooCommerce-automations concept, mirroring how
engine-run automations already live there.

**Tests:** component — rewrote `TransactionalEmailsSection.test.tsx` down
to the Connection-tab credential/toggle behavior (incl. a regression guard
that the rendered text never contains "sub-account"); new
`TransactionalTriggersSection.test.tsx` (absent — empty render, zero
`/workflows` fetch — when not connected; present + fetches the
`'transactional'` workflow list, never `'default'`, when connected; the
shipped-status checkboxes still read `env.orderStatuses`); new
`Settings.transactionalPlacement.test.tsx` pinning the tab placement in
the real `Settings` shell (toggle+credentials render under `#connection`;
the WC tab renders nothing transactional while disconnected and both
trigger headings once connected). Unit — `SettingsEndpointTest`'s combined
woocommerce-tab transactional test split into a connection-tab test (the
account) and a woocommerce-tab test (the triggers + a regression assertion
that the woocommerce tab does NOT write the transactional-account
options). Integration — `SettingsRoundTripTest`'s combined round-trip test
split the same way; `TransactionalEmailsPipelineTest::configure()` updated
to POST the account via `tab: 'connection'` and the triggers via `tab:
'woocommerce'` (the old single `tab: 'woocommerce'` POST would otherwise
silently no-op the account fields under the new routing — caught by the
full integration run, not by unit tests, since the unit suite doesn't
exercise `TransactionalGate`'s live send path end to end).

**Relationships:** supersedes ONLY the UI placement decided in PRO-1504
Stage 1 (the "config-only surface" and Stage 2 "the sender" decisions
above are otherwise unchanged — same options, same trigger types, same
send pipeline). `AutomationSection`'s `accountKeyOverride` prop (Stage 1)
is reused unchanged by the relocated `TransactionalTriggersSection`.

---

### PRO-1685 — The Smaily queue applies the written retry policy: a permanent refusal stops, a temporary one backs off

**Context:** the Smaily-side flushers (`Flusher`, `CartFlusher`) caught
EVERY `ApiException` as transient and called `record_attempt()`. Nothing
ever read the counter it bumped and outstanding rows are deliberately never
pruned, so a refusal that can never succeed — 401 on revoked credentials,
403, 404 on a deleted workflow, 422 on a rejected address — was re-POSTed
on every 60s tick indefinitely: the queue grew for as long as the condition
lasted, the oldest-first drain kept handing the doomed rows the batch slots
ahead of fresher work, and because `NotificationManager::failed_last_24h()`
counts only `status = 'failed'`, the "N sync events failed" notice never
fired for work that never stopped being retried. The policy was already
WRITTEN — `ApiException`'s and `Client`'s docblocks ("4xx no-retry, 429
honour Retry-After, 5xx exponential backoff"), `EventQueue::record_attempt()`
("when attempts reaches the policy ceiling the caller flips the row to
STATUS_FAILED"), spec `PLUGIN.md` §8 ("…max 5 attempts") — and simply never
applied on this side. The rec-engine queue has applied its half of the same
policy since F3-18 (`AbstractD6Flusher::is_terminal()` + `max_attempts` +
`next_retry_at`).

**Decision:** implement the written policy, in ONE place —
`Smaily\RetryPolicy`, applied by both marketing flushers:
- **Permanent = 4xx except 429.** `mark_failed` immediately, reason
  `permanent_http_<code>: <message>`. No retry attempts consumed.
- **Temporary = everything else** (5xx, 429, and a transport error with
  code 0), retried with `next_retry_at` spacing on the SAME ladder the rec
  queue uses (1m, 5m, 15m, 1h, 6h) — or for exactly the `Retry-After` Smaily
  sent (capped at 6h) — up to `MAX_ATTEMPTS = 5`, then `mark_failed` with
  `retry_limit_exceeded after N attempts: <message>`.
- `Client::request()` now parses the `Retry-After` header (delta-seconds
  form only; an HTTP-date falls back to the ladder) onto the exception —
  that is the only way "honour Retry-After" can reach the queue, since the
  client itself deliberately does not retry (F3-10).
- Schema: migration 010 adds `next_retry_at` + `idx_status_retry` to
  `smly_plus_event_queue`, mirroring the rec queue (migration 004). NULL =
  due now, so every existing row and every fresh enqueue is unaffected.

**Rationale for the bias:** the real risk here is MIS-classification —
treating a recoverable failure as permanent silently drops genuine work,
which is worse than a bounded number of wasted retries. So anything not
recognisably a permanent refusal is temporary, explicitly including a
transport error that never received a status. The blast radius either way is
bounded by the Event Log recovery path: `POST /events/retry` →
`EventQueue::reset_failed()` resets status + attempts + (now) the retry park
for ANY row in this queue, whichever flusher owns it. Marking rows failed
also lets the existing `QueueJanitor` prune them — after 90 days, ten times
the `sent` retention, and only once they are long past diagnosis; `pending`
rows are still never pruned.

**Scope — deliberately marketing rows only.** `TransactionalFlusher` keeps
its own PRO-1519 bound (a time ceiling, not attempts) and passes no backoff,
so it still retries on every tick: a pending transactional row SUPPRESSES the
customer's native WooCommerce email the whole time it waits, so what must be
capped there is elapsed time, and spacing its retries out would only delay
the fail-open. That is why `record_attempt()`'s backoff argument defaults to
0 (due immediately) rather than to the flush cadence.

**Alternatives considered:** (a) deriving due-ness from `created_at` +
cumulative backoff to avoid a schema change — rejected as fragile and
unlike the proven sibling queue; (b) a `max_attempts` COLUMN like the rec
queue — rejected, nothing varies it per row, and it would render as a
misleading "n/5" for the transactional rows sharing the table (the Event Log
keeps projecting NULL for Smaily rows; the reason lives in `last_error`);
(c) classifying by Smaily's in-body `{code}` envelope — out of scope, that is
sibling PRO-1686 (which refusals count as permanent for PLAN reasons); the
non-101 body codes the cart/transactional paths already treat as terminal are
untouched.

**Relationships:** applies F3-10's "row-level retry goes through the queue
table" half on the Smaily side; mirrors F3-18/N-7.1's terminal-4xx split
(`AbstractD6Flusher`); extends F3-53's "a deterministic throw must never
become an eternal retry loop" from Throwables to HTTP refusals; leaves
PRO-1519 (transactional time ceiling) and PRO-1195 (cart flusher ownership)
intact.

---

### PRO-1681 — Each automation trigger marks the contact with its last run time

**Context:** a contact Smaily holds only because a store automation enrolled
them was indistinguishable from someone who subscribed themselves. Of the
three store-run triggers only abandoned cart sent anything at all
(`is_abandoned_cart`), and that is a legacy TEMPLATE flag, not a record that
the automation ran; welcome and first order sent nothing. A merchant could
neither target nor exclude the automation-touched group when building a
Smaily segment.

**Decision (Erkki, 2026-08-04):** each trigger writes its OWN contact field
whose VALUE is when that automation last ran for the contact:

| trigger | field |
| --- | --- |
| `welcome` | `welcome_automation_at` |
| `first_order` | `first_order_automation_at` |
| `abandoned_cart` | `abandoned_cart_automation_at` |

- **Written on EVERY run, last-writer-wins.** The semantics are "this
  automation ran, most recently at T" — deliberately NOT entry origin, so an
  already-subscribed contact gets it too. Accepted with eyes open: "when did
  welcome last touch this contact" is the question a segment can actually be
  built on, and an origin flag would need a state the plugin doesn't hold
  (whether the contact existed in Smaily before the trigger). The value is
  stamped when the trigger FIRES (payloads are built at enqueue), not when
  the row is POSTed — so a retried or delayed send still carries the moment
  the store event happened.
- **Format `Y-m-d H:i:s` in UTC**, from the one place that decides it,
  `Smaily\AutomationMarker`. That is the shape of the only other date+time
  value already on the Smaily contact wire (`first_registered`, passed
  through raw from `user_registered`), and it sorts lexicographically, which
  is what lets a Smaily segment compare it against a date. Deliberately NOT
  the rec-engine's `IsoDate` Z-form — that is the engine's strict Zod
  contract (F3-21), a different wire.
- **Omit, never empty.** A trigger writes only its own field; nothing is sent
  for an automation that didn't fire, so Smaily keeps whatever it holds
  (F3-47 rule 2). A plain `contact.sync` carries no marker at all.
- **Additive only.** `is_abandoned_cart` keeps its exact name and meaning
  (PRO-1195 legacy template parity) and the abandoned-cart marker rides
  alongside it in the same payload; no existing field changed.

**Rationale for the names:** they are permanent, merchant-visible
segment/template identifiers — a merchant builds a Smaily segment on
`welcome_automation_at` and a rename silently breaks it, with no error
anywhere. They follow the wire's existing snake_case, carry the trigger slug
the Settings/router vocabulary already uses (`welcome`, `first_order`,
`abandoned_cart`), and the `_at` suffix says the value is a time, not a flag.
`AutomationMarkerTest` asserts each name literally so a rename is a failing
test, not a support ticket.

**Scope:** the three STORE-run triggers only. The transactional triggers
(`order_confirmation`, `shipping_confirmation`) deliberately have no marker —
they are a receipt for an order, not an enrolment into marketing;
`AutomationMarker::stamp()` returns an empty array for them, which is the
omit path. Whether enrolment should happen at all, and what the merchant may
send to the enrolled group, are out of scope (accepted constraints).

**Demonstration:** `tests/Integration/AutomationMarkerPipelineTest.php`
drives the three REAL pipelines on the running store (`user_register` →
welcome; `woocommerce_store_api_checkout_order_processed` → first order;
cart tracker → sweep → `CartFlusher` → abandoned cart) with only the Smaily
transport faked, asserting each marker's name and UTC format on the wire,
that `is_abandoned_cart` and the product matrix are untouched, and that the
contact syncs in the same flush carry NO marker. Confirmed to pin the change
by reverting the three call sites (3/3 fail).

**Relationships:** additive to PRO-1195 (cart wire parity) and PRO-1680
(product matrix); follows F3-47's omit-vs-empty rule; parallel to but
separate from F3-21's `IsoDate` (rec-engine wire).

### PRO-1684 — The stored field selection is read in BOTH shapes; an unreadable one is told, not guessed

**Context:** a store upgraded from a pre-wizard Connect version synced only
`email` + `store`, silently. The legacy settings page wrote the selection as a
MAP — `Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS` keys → bool, produced by
`Sanitizer::sanitize_subscriber_sync_fields()` (deleted with the legacy view
layer at F3-45, 9a02618) — while `SubscriberPayloadBuilder` reads a LIST of
enabled names. Read as a list, the map yields `'1'`/`''` values, the
SUPPORTED_FIELDS intersection matches nothing, and every optional field
vanishes. The wizard then showed the OPPOSITE: the map reaches the browser as
a JS object whose missing `.length` made `hydrate.ts` fall back to "every box
ticked", so the merchant saw ten fields ticked while none was being sent.

**Decision:** one read-side interpreter,
`SubscriberPayloadBuilder::interpret_selection()`, understands both shapes and
returns the canonical list — or `null` when the value is neither.
`effective_selection()` (option → interpreter → documented default) is the
single source for BOTH readers: the payload builders and the wizard's
hydration (`EnvDetector::saved_settings()`), so a tick the merchant sees always
means the field is being sent.

- **The map's VALUES are honoured** — a legacy `false` is a real "don't send
  this", the same answer the legacy sync itself gave (`array_keys(
  array_filter( $options ) )`). "Same fields as before the upgrade" is the
  values, not the key set.
- **The legacy → current key map** (`LEGACY_SELECTION_KEYS`): `user_dob` →
  `birthday` is the one real rename; `customer_group`, `customer_id`,
  `first_name`, `first_registered`, `last_name`, `nickname`, `site_title`,
  `user_gender`, `user_phone` are unchanged; `store_url`, `user_email` and
  `language` map to nothing — they were never optional (`email`/`store` are
  sent unconditionally per FIELD_MAPPING.md §1, language is resolved by
  `ContactLanguageResolver`, F3-47).
- **Read-side, not a write-migration** — the PRO-1683 precedent, for the same
  reason: it heals however the plugin was updated instead of depending on an
  upgrade hook a ZIP install may never fire. The issue's "take a copy before
  converting" concern is met by never converting: the stored value is left
  exactly as the legacy page wrote it, so an interpretation we got wrong is
  undone by shipping a different interpreter, with nothing to restore.
- **Unreadable is admitted, not guessed.** Neither shape (no known legacy key,
  or values no writer could produce) ⇒ the sync falls back to the documented
  default (every cross-channel field on, the same fallback a never-saved
  option gets — NOT the bare minimum) and a dismissible `notice-warning` tells
  the merchant to re-save their selection (`NotificationManager::
  SYNC_FIELDS_ADVISORY_KEY`, the F3-50 consent-advisory pattern: live, not
  cron-driven, 24h dismiss cooldown). A never-saved option is NOT unreadable —
  a fresh install is not nagged. The advisory deliberately reads no other
  option: gating it on the sync-enabled toggle would entangle it with that
  key's own drift (PRO-1742), and a selection worth fixing is worth fixing
  before sync is switched back on.
- **Unknown NAMES inside a shape we recognise are still dropped**, not
  escalated to unreadable: a stale name says nothing about the rest of the
  merchant's choice, and this is what the list shape already did.
- **`hydrate.ts` now treats an empty list as an answer** (`Array.isArray`, not
  `.length > 0`). The legacy default — nothing optional ticked — is an empty
  selection, and the old length check rendered exactly that case as "all
  ticked".
- **Nothing on the wire changed:** field names and value forms are untouched
  (PRO-1678 ground rule), and a field with no source value is still OMITTED,
  never sent empty (F3-47 rule 2).

**Demonstration:** `tests/Integration/SubscriberSyncFieldSelectionTest.php`
seeds the option through `Support\LegacySettingsPage` — the legacy sanitizer's
method body copied verbatim from `9a02618^`, verified to return arrays
identical to the historical class over every shape a merchant could post — and
drives the REAL sync pipeline and the REAL wizard hydration with only the
Smaily transport faked. Confirmed to pin the change by disabling the legacy
branch (4/11 fail) and by restoring the `.length` check (2/3 vitest fail).
Confirmation against a genuine production store's stored value is human
acceptance.

**Relationships:** same class of bug and the same read-side remedy as PRO-1683
(`phone`/`gender`); the advisory follows F3-50's config-advisory pattern; the
omit-vs-empty rule is F3-47's.

### PRO-1682 — The welcome trigger keys on the WooCommerce account-creation FLOW, never on the role

**Context:** the welcome automation fired on any `user_register` — a staff
account added in wp-admin, an account a membership or forum plugin created,
anything. Enrolment can't be suppressed once a trigger fires (accepted
constraint), so every such account became a marketing contact of the merchant's
with no customer relationship to rest a legitimate-interest basis on.
Controlling WHO triggers is the only lever.

**Decision:** the welcome fires from **`woocommerce_created_customer`** and no
longer from a bare `user_register`. That hook is WooCommerce's own "a shopper
got an account" signal: `wc_create_new_customer()` fires it, and every shopper
flow goes through that function — classic checkout
(`WC_Checkout::process_customer`), block checkout
(`StoreApi\Routes\V1\Checkout::create_customer_account`), My Account
registration (`WC_Form_Handler::process_registration`), the order-confirmation
"create an account" block, `WC_Customer::save()`. wp-admin's Add New User and a
plain `wp_insert_user()` fire neither.

- **The signal is the flow, not the role.** A role allowlist was rejected: just
  `customer` drops a store's own shopper roles (wholesale, VIP), and widening
  it to `subscriber` re-admits exactly the forum/membership accounts this is
  about. `wc_create_new_customer()` lets a plugin swap the role through
  `woocommerce_new_customer_data` and still fires the hook, so custom shopper
  roles keep working **because the role is never consulted** — and no staff-role
  denylist has to be maintained as new roles appear. This deliberately does NOT
  transfer to F3-20's A-filter: that decision is about rec-engine training data
  ("non-shopper accounts are harmless noise"), where the consequence of a false
  positive is noise; here it is a staff address in a marketing list. The
  rec-engine customer path is untouched.
- **Exactly one trigger.** Both hooks fire in the same request for a
  WooCommerce-created account (`user_register` from inside `wp_insert_user`,
  then `woocommerce_created_customer`); the existing per-request `maybe_enqueue`
  dedupe on `automation.welcome:{user_id}` collapses them, and the integration
  case asserts a single enrolment.
- **Contact sync is unchanged.** Who is synced as a contact is still
  `ContactAudience`'s mode-aware decision (F3-48) on both hooks — only the
  welcome ENROLMENT narrowed.
- **Filter `smaily_connect_welcome_eligible`** ( bool $eligible, int $user_id,
  string $source ) is the documented escape hatch: default `true` for
  `woocommerce_created_customer`, `false` for `user_register`. A store whose
  shopper accounts are created outside WooCommerce's flows widens it on
  `user_register`; a store that wants even less narrows it. The filter is
  consulted only when the welcome toggle is on.
- **Known limit, stated rather than engineered around:** an unrelated plugin
  that creates accounts THROUGH `wc_create_new_customer()` is indistinguishable
  from a shopper registering, and still triggers. The criteria are met by
  flow distinction, not omniscience.

**Demonstration:** `tests/Integration/WelcomeTriggerAudienceTest.php` creates
REAL users through the REAL paths on the running store — `wc_create_new_customer()`
plain, with checkout's argument shape, and with a custom `wholesale` role
swapped in through `woocommerce_new_customer_data`; `wp_insert_user()` with
`administrator` / `editor`; `wp_insert_user()` with the default role — and
asserts which of them reach the Smaily transport on the welcome workflow, with
only the transport faked. Confirmed to pin the change by restoring the
`user_register` default to eligible (2/6 fail; 2 more in the unit gate).

**Relationships:** narrows the trigger PRO-1681 marks (`welcome_automation_at`,
unchanged); deliberately does NOT change F3-20's rec-engine A-filter or F3-48's
contact-sync audience; the wizard's trigger description and the merchant docs'
Welcome bullet were corrected in the same commit (both languages).

### PRO-1742 — One accessor owns the "Sync contacts to Smaily" switch, and an OFF answer is actually stored

**Context:** the switch has always been written as
`smaily_connect_subscriber_sync_enabled` (the wizard's save route today, the
pre-wizard settings page before it), but the live sync gated on
`smly_plus_subscriber_sync_enabled` — a key **no version of this plugin has
ever written** (verified across the whole git history). Both default to on, so
nothing looked wrong day to day; a merchant who switched contact sync OFF kept
sending every account change to Smaily. Reproduced on the running store before
any fix: the switch saved off through the real REST settings route, then a new
account + opt-in + profile edit still POSTed two contact rows, and the contact
backfill POSTed as well. This is the third settings-key drift found in a day
(PRO-1683, PRO-1684).

**Decision:** the legacy key is canonical — it is the one every version has
written, so an upgraded store's stored choice is honoured with no migration —
and every surface reads it through one accessor,
`ContactSyncMode::sync_enabled()` (default on, never memoised).

- **Consumers:** the four live gates in `HookHandler` (new account, profile
  update, order-path contact sync, consent change), `ContactAudience`, and
  the wizard's hydration (`EnvDetector`). `SettingsEndpoint`'s own constant is
  now defined AS `ContactSyncMode::OPTION_SYNC_ENABLED`, so the key has
  exactly one spelling in the plugin. The dead `smly_plus_` constant is gone.
- **`ContactAudience` carries the switch, so the backfill honours it too.**
  Switched off, the audience is empty whatever the mode says — the mass walk
  sends nothing, and the "about N of them will be synced" estimate says 0
  instead of promising a sync that will not happen. This keeps the backfill,
  the live hooks and the wizard's number on one decision, and matches what
  the legacy daily cron did (it gated on this same option).
- **An OFF answer is now storable.** `update_option( $key, false )` on an
  option that has never been saved concludes "nothing changed" and writes
  NOTHING — with a default of ON that silently discarded a merchant who
  switched the sync off during the initial wizard. The save route writes
  `'1'` / `''` instead, the same on-disk shape the legacy settings page left.
  Every other flag on that tab defaults to off, where the lost write is
  invisible; only this one needed it.
- **Automations are NOT coupled to it.** Welcome / first-order / abandoned
  cart keep their own toggles and their own consent basis, which is what the
  merchant docs already promise ("existing contacts and Smaily automations
  are unaffected") — turning contact sync off must not silently disable an
  automation the merchant enabled separately.
- **Known semantic, unchanged:** on the legacy page "never enabled" and
  "never configured" are the same absent option, and absent reads as ON here.
  A store only reaches the new sync path after finishing the wizard, which
  asks the question again with the switch visible — so the wizard's answer,
  not the legacy absence, is what governs.

**Demonstration:** `tests/Integration/ContactSyncToggleTest.php` saves the
switch through the REAL settings route on the running store, then drives the
REAL account hooks and the REAL contact backfill with only the Smaily
transport faked, asserting nothing reaches it when off and the same changes
still do when on; the legacy fixture is written by `Support\LegacySettingsPage`
(`rest_sanitize_boolean`, as that page registered it) rather than by hand.
Confirmed to pin the change: 3 of the 5 cases fail against the pre-fix code.

**Relationships:** same class of bug as PRO-1683 / PRO-1684 and the same
read-side remedy (translate on read, never migrate the stored value); the
audience gate is additive to F3-48's mode policy; the automation decoupling
follows PRO-1682's reading of what each trigger's consent basis is.

### PRO-1716 — "Force opt-in on automation triggers" is retired; a trigger never re-subscribes, in any preset

**Context:** F3-48.4 made the automation `force_opt_in` flag mode-driven and
added one escape hatch: an advanced Step-2 toggle, visible only under the
legitimate-interest preset, that let a store send `force_opt_in=true` — a
welcome / first-order / abandoned-cart trigger would then override an
unsubscribe the contact had made in Smaily. It shipped default OFF and stayed
off: every store that never touched it — which is every store on a fresh
install — already got `false`. Jane's PRO-1645 review asked for it to go, on
the PRO-1678 ground rule that triggering an automation for a non-contact adds
them as an opted-in contact (an accepted constraint) but never overrides an
existing opt-out. Approved by Erkki (2026-08-04).

**Decision:** remove the setting; `AutomationRouter` passes `false` outright.
The surviving behaviour is exactly the default state's, so a store that never
touched the toggle sees no change at all.

- **A store that had it ON loses the override**, deliberately: its triggers
  stop re-subscribing contacts who unsubscribed in Smaily. This is the only
  behaviour change in this work, and it is the point of it. Nothing else about
  the trigger changes — the same workflows fire for the same people, and a
  contact Smaily has never seen is still enrolled.
- **The stored option is left in place** as a harmless orphan (nothing reads
  it). No migration step, no merchant action; `uninstall.php` already sweeps
  every `smly_plus_*` row by prefix, so it needs no cleanup line of its own.
- **The REST route stays tolerant.** A browser holding a cached pre-PRO-1716
  admin bundle still posts `automationForceOptIn`; unknown keys are simply not
  read, so the save succeeds and the retired option is never written again.
- **`Client::trigger_automation()`'s default flipped to `false`** to match its
  two callers, so a future trigger can't opt into re-subscribing by omission —
  which is how the pre-F3-48.4 code sent `true` everywhere.

**Demonstration:** `tests/Integration/AutomationForceOptInTest.php` drives a
welcome trigger through the real queue + flusher with only the transport faked
and asserts `force_opt_in=false` in the three states a live store can be in
(option never saved, saved on, saved off), plus a settings save carrying the
retired field returning 200 and leaving the orphan untouched;
`AutomationRouterTest` pins the same three states at the unit seam, and
`Step2Subscribers.forceOptIn.test.tsx` pins that the control renders nowhere
under the one preset that ever showed it (the wizard and Settings render the
same component).

**Relationships:** narrows F3-48.4 (mode-driven `force_opt_in`) to a constant;
keeps F3-48's other sub-option (`include_guests`) untouched; follows the
PRO-1678 ground rules and Jane's PRO-1645 point 3. The merchant docs site never
documented the setting, so it needed no change.

### PRO-1715 — A contact import with nothing to sync finishes at start(), it does not wait for a tick

**Context:** on a store where nobody is in the contact-sync audience — a fresh
install whose only user is the administrator, a consent-mode store with no
opt-ins, or (since PRO-1742) a store whose "Sync contacts to Smaily" switch is
off — pressing **Start import** left the panel spinning until the merchant
cancelled by hand (Jane's PRO-1645 point 1). The job itself was never the
problem: it completes the moment a batch runs. The problem was the *only* way
out of the `running` state being an Action Scheduler tick. `/backfill/start`
seeded the row as `running` and enqueued an async tick; on a quiet store that
tick can be minutes away (reproduced on the dev store: five ticks queued and
unprocessed), and the panel has nothing to show in the meantime — 0 walked, 0
synced, a spinner that never moves. A store WITH contacts hides this, because
the first tick that does run produces visible progress.

**Decision:** decide "there is nothing to sync" up front and close the run
synchronously, so no store depends on a background tick to be told nothing
happened.

- **`BackfillJob::start()`** asks `has_empty_audience()` (the existing
  `ContactAudience::count_audience()`, the same definition the walk filters
  on) and, when it is empty, marks the freshly-seeded row `completed` right
  away — `processed_count = total_count` (with an empty audience every user
  would have been audience-skipped, so the walk is genuinely finished),
  `synced_count = 0`, `completed_at` now. It also leaves the `_smaily_synced_at`
  freshness markers alone: a run that syncs no-one has nothing to re-sync.
- **`BackfillEndpoint::start()`** reads the row before scheduling and enqueues
  the first tick **only while the row is still `running`** — a tick on a
  finished job would flip it back to `running` and put the merchant in front of
  the same spinner. `Bootstrap::maybe_start_contact_refresh()` (the daily
  refresh) gets the same guard.
- **The admin panel** stops assuming a start means "running": the hook adopts
  the response's status and, when it is already terminal, pulls the real status
  payload. Step 2 then names the outcome — *"Nothing to import — no contacts
  match your synchronization settings."* — instead of "Done — 0 contacts
  synced", which reads like a failure.

**Why not just disable the button at an audience of 0:** the estimate is a
snapshot the panel fetched on mount, and the daily refresh starts runs with no
button involved. Deciding it server-side at start() covers every caller;
the copy change is what the merchant reads.

**Demonstration:** on the running dev store (1 user, consent mode, audience 0)
`/backfill/start` answers `{"status":"completed"}` in ~5 ms with zero ticks
scheduled, and `/backfill/status` carries `audience_estimate: 0`; with one
opted-in user present the same route answers `running` with one tick scheduled
(behaviour unchanged). Pinned by
`ContactBackfillAudienceTest::test_starting_with_an_empty_audience_finishes_the_run_without_a_tick`
(real REST route + real Action Scheduler — fails on the pre-fix code with
`running`), `BackfillJobTest` / `BackfillEndpointTest` at the unit seam, and
`useBackfillProgress` + `Step2Subscribers.backfillCopy` on the JS side.

**Relationships:** completes PRO-1742 (an OFF switch yields an empty audience —
this is what that case now looks like to the merchant); reuses F3-55's audience
estimate as the UI's "nothing to sync" signal; does not touch the rec-engine
backfills, whose `start()` never returns a terminal status. Merchant docs: the
import status table gains the new outcome line in both languages.

### PRO-1717 / PRO-1718 / PRO-1720 — three wizard trims: no premature Campaign Intelligence pitch, the account link where it is needed, no Event Log homework

**Context:** Jane's PRO-1645 walkthrough of the setup wizard (approved by Erkki
2026-08-04) found three places where a step says something the merchant either
cannot act on yet, or no longer needs.

**Decisions:**

- **PRO-1717 — step 3 shows the Campaign Intelligence automations only to a
  connected store.** A store without Campaign Intelligence used to get an upsell
  banner for engine-run automations on step 3, one step before the step that
  introduces Campaign Intelligence and carries the connection form: the same
  pitch twice, the first time with nothing to click. `EngineAutomationsSection`
  now renders `null` when the engine is not connected AND it is not in Settings.
  A connected store's section is untouched. **Settings keeps its upsell** — it
  has no "next step", and that banner's CTA is the pointer to the Campaign
  Intelligence tab. The banner stays reachable in the wizard through one edge
  only: the `not_connected` load failure (boot said connected, the proxy
  disagrees), where "connect at the next setup step" is still the right advice.
- **PRO-1718 — the Smaily account link moved from the last step to the first.**
  It opens `https://<subdomain>.sendsmaily.net`, which is exactly where step 1
  sends the merchant to create the API user; on the closing overview step it
  arrived after the need had passed. It is **removed from the last step**
  (Jane's ask; no reason found to keep it in both places — the overview is a
  read-only summary and Settings → Connection carries the account details for
  later). It renders **only once a subdomain is known**, because the subdomain
  IS the account address — a permanently disabled button on a fresh install
  would say nothing. Consequence: the overview's "Open dashboards" card had one
  possible occupant left, so it renders only when the Campaign Intelligence
  dashboard exists.
- **PRO-1720 — the Event Log advisory is gone from the last step.** It told the
  merchant to watch the Event Log during the first days of operation; nothing
  there needs watching by hand — the health check raises its own admin notice
  linking to the log when something fails — so it was length on the step that
  should read "you're done".

**Alternatives rejected:** keeping a disabled account link on step 1 for
discoverability (a dead control explains nothing); hiding the step-3 section in
Settings too (Settings has no connection step to point at).

**Demonstration:** component tests render each surface both ways —
`EngineAutomationsSection.test.tsx` (wizard + disconnected → empty DOM, no
catalog fetch; wizard + connected → section and triggers unchanged),
`Step1Connect.smailyLink.test.tsx` (opens the seeded subdomain's account, absent
with no subdomain, absent in Settings), `Step6Done.test.tsx` (no Event Log
mention, no Smaily link, rec-engine card only when connected).

**Relationships:** the moved string keeps its PRO-1746 Estonian wording ("Ava
oma Smaily konto →"); catalogs rebuilt with `bin/build-i18n.sh`. The merchant
docs site describes none of the three surfaces, so nothing there became false.

### PRO-1769 — The contact import pages in SQL (`WHERE ID > cursor`), not in PHP after a first page it re-reads

**Context:** `BackfillJob::fetch_users_after()` asked `get_users()` for the
first `$batch_size` users (`orderby ID ASC`, no offset, no cursor) and then
pruned `ID > cursor` in PHP. Tick 1 walked page one correctly; tick 2 asked for
page one AGAIN, filtered every row away as already-past-the-cursor, and the
empty page satisfied `count( $users ) < $batch_size` — the walk's "no more
users" signal — so the job marked itself `completed`. A store with more than
one page of users imported only its first 100 contacts and reported success.
**Reproduced on the dev store before any fix** (151 users, 150 opted in, the
real REST `/backfill/start` + real Action Scheduler ticks, Smaily transport
faked): two ticks, 99 of 150 opt-ins POSTed (`pro1769_001..099`), row
`completed` with `processed_count=100/151`, `/backfill/status` reporting
`{"status":"completed","percent":66,"synced":99,"audience_estimate":150}`.

**Why nobody reported it:** a store whose whole user table fits in one batch —
which is most WooCommerce stores, and every automated test we had (the
integration walk used `process_batch( 200 )` against ~10 users) — behaves
correctly, because the FIRST page is also the last one. On a bigger store the
failure is silent by construction: the panel says *completed*, the first 100
contacts really do arrive in Smaily, and everyone who registers or orders after
that arrives through the live hooks — so the store looks synced. It never
self-heals either: the daily refresh re-seeds the cursor at NULL, re-walks the
same first page (skipping it as fresh) and completes again.

**Decision:** page the walk the way the rec-engine backfills already do —
`SELECT ID FROM wp_users WHERE ID > %d ORDER BY ID ASC LIMIT %d`
(`CustomerBackfillJob::fetch_ids_after()` is the template), then hydrate that
id list through `get_users( include )`. The cursor, the walked count and the
"was this the last page" test all read the ID page, so a user deleted between
the two queries cannot stall the cursor. Audience semantics are untouched:
`ContactAudience::should_sync_user()` still filters each hydrated user
(PRO-1742's switch, F3-48's presets) and PRO-1715's empty-audience fast path
still closes a run at `start()`.

**Alternatives:** an `offset`-based `WP_User_Query` (rejected — a user deleted
mid-walk shifts the window and silently skips someone; the codebase's own
pattern is an id cursor); leaving `get_users()` to do the paging (it has no
"after id" argument, which is what produced the PHP-side prune in the first
place).

**Relationships:** pinned twice — a unit test asserts the stored cursor reaches
the QUERY (`WHERE ID > %d` with the cursor and the batch size as its args), and
`ContactBackfillAudienceTest::test_a_store_with_several_pages_of_users_syncs_every_audience_member`
runs the real walk with a batch size of 2 and asserts every seeded audience
member was POSTed (it fails on the pre-fix code). Re-demonstrated on the dev
store after the fix: 150/150 opt-ins POSTed, `processed 151/151`, `percent 100`;
a single-page store (6 users) still finishes in one tick, unchanged. Nothing in
the merchant docs site described the paging, so nothing there became false.

### PRO-1743 — The legacy readers of the field selection get the interpreter's answer in their own key names

**Context:** PRO-1683/PRO-1684 taught the SYNC to read the merchant's field
selection in both stored shapes (the wizard's list of names, the pre-wizard
settings page's map of name => on/off). Two readers in the legacy
`Smaily_Connect\*` tree were left behind, both still doing
`array_keys( array_filter( $option ) )`: `Profile_Settings::
filter_enabled_fields()`, which decides whether the plugin's Phone / Gender /
Birthday inputs are printed on the WordPress profile, the WooCommerce account
form and the checkout, and `Subscriber_Synchronization::order_optin_subscriber()`,
which builds the contact for a guest who ticks the checkout newsletter box. On
a wizard-configured store that map read matches nothing, so the store could not
COLLECT the very meta the sync had just been fixed to send, and a guest opt-in
went to Smaily without so much as an email address. The forms reader is live
whenever WooCommerce is active and credentials are saved; the opt-in reader
only until the wizard finishes (LegacyHookBridge then strips its hooks).

**Decision:** both read through `SubscriberPayloadBuilder::
effective_selection_legacy_keys()` — the same interpreter, re-expressed in the
LEGACY option key names those readers speak (`user_dob`, not the contact
field's `birthday`). `store_url` / `user_email` / `language` are always in that
answer: the legacy settings page forced all three on and never let them off,
and in the new namespace they are not a merchant choice at all (email and store
are sent unconditionally, language is resolved by ContactLanguageResolver).

**Rationale:** the legacy namespace already calls into the new one where the
logic is shared (`Cron` uses `ContactLanguageResolver` / `AutomationRouter`), so
there was a pattern to follow rather than a bridge to invent. Handing the
readers canonical names instead would have meant a second translation table
inside the legacy tree — exactly the drift that produced this issue.

**Alternatives:** rewriting the two readers to speak canonical names (rejected —
larger diff in code we keep close to upstream, and `user_dob` is the meta key
the form field itself is named after, so the rename would have to stop halfway);
migrating the stored option to one shape (rejected for the third time, same
reason as PRO-1683/PRO-1684 — a read-side fix heals a store however it was
updated, an upgrade routine only heals the stores that run it).

**Demonstration:** `tests/Integration/SubscriberFieldFormsTest.php` renders the
REAL form hooks (`show_user_profile`, `woocommerce_edit_account_form`,
`woocommerce_checkout_fields`) and drives the REAL guest opt-in path on the
running store with only the Smaily transport faked, against both stored shapes;
2 of its 6 cases fail against the pre-fix code (no inputs printed at all; a POST
with no email). Unchanged for a legacy-configured store, including that an
unticked box stays unticked.

**Relationships:** completes PRO-1683/PRO-1684 (the sync side) — the selection
now has exactly one interpreter with two vocabularies. `Data_Handler::
get_user_data()` still reads the option as a map for the registered-user opt-in
path; same class of bug, same pre-wizard-only window, left as a follow-up rather
than folded in.

**PRO-1772 addendum — the fourth reader, and the last known one.** That
follow-up was the same bug one call deeper: `Data_Handler::get_user_data()`
iterated the selection as `foreach ( $options as $field => $enabled )`, so on a
wizard-shaped (list) selection the loop variable is an integer index and every
`switch` case misses. The array it returns is what
`Subscriber_Synchronization::update_subscriber()` POSTs, so the contact reached
Smaily with **no email address and no fields at all** — an API error in the log
and nothing updated. Its reach is wider than the guest opt-in above: the
WordPress profile save (`personal_options_update` / `edit_user_profile_update`),
the WooCommerce account-details save, `woocommerce_created_customer`, and the
registered-user branch of the very opt-in PRO-1743 fixed for guests — all in the
same pre-wizard-Finish window (LegacyHookBridge strips these hooks at Finish).
The one other caller, `Cron::smaily_sync_subscribers()`, is the retired legacy
mass-send: kept for the upstream diff, deliberately never registered (F3-53),
so it is not a live path — but it reads the same method and is now correct
too. **Same decision, same bridge:** it reads
`effective_selection_legacy_keys()`, which its `switch` already speaks
(`user_dob`, `user_phone`, …), so the fix is the one-line read swap and no fifth
translation table. The always-present trio keeps the meaning established above —
and for this reader it is also literally what a pre-wizard store already had,
since the legacy sanitizer forced `store_url`/`user_email`/`language` true.
**Demonstration:** two cases in `tests/Integration/SubscriberFieldFormsTest.php`
drive the registered-customer branch of the real checkout opt-in on the running
store with only the Smaily transport faked (the order's billing email is
deliberately a DIFFERENT address, so the assertion proves the contact is built
from the user's profile). The wizard-shaped case fails against the pre-fix code
(`email` null, payload empty); the legacy-shaped case passes before and after —
behaviour unchanged. With this, every known reader of the field selection goes
through the one interpreter.

### PRO-1686 — A refusal is reported under the cause Smaily gave: the package, the credentials, or an outage

**Context:** when a Smaily account moves to a freemium package, everything the
plugin does stops being permitted — and the plugin reported it as two things it
was not. After an hour the health notice said *"the Smaily API has been
unreachable … until the connection recovers"* (it was reachable, and nothing
recovers by waiting) and the connection test said *"Smaily did not accept those
credentials"* (they were never wrong). Both surfaces knew only a boolean:
`Client::test_connection()` returned false for every kind of failure.

**Probe (live, 2026-08-04, a real freemium account).** Every endpoint the plugin
uses — `autoresponder.php`, `contact.php` (read and `list=1`), `history.php` —
answers `HTTP 403 {"code":227,"message":"A paid package is required."}`. `227` is
Smaily's documented *Paid Plan Required* code
(https://smaily.com/help/api/general/response-codes/) and nothing else produces
it, so it is a POSITIVE plan signal, not an inference. It is returned identically
for the correct credentials, a wrong password, a wrong username and NO
`Authorization` header at all — the package check runs BEFORE authentication.
Two neighbouring shapes for contrast: a subdomain that does not exist answers
`404` with an empty body; there is no `WWW-Authenticate` header anywhere.

**Decision:** one classifier, `Smaily\RefusalReason::classify()`, reads a failed
request and names the cause — `PLAN_BLOCKED` (Smaily code 227, whatever HTTP
status carries it), `CREDENTIALS_REJECTED` (any other 4xx bar 429, including the
404 a wrong subdomain gives — the subdomain is part of the credential triple the
merchant typed), `UNREACHABLE` (429, 5xx, transport error). `ApiException` now
carries Smaily's own body code alongside the HTTP status, `Client::
test_connection(): bool` became `check_connection(): string`, and both merchant
surfaces phrase their own message per cause: the connection test's `error`
string and the health notice's key (`smaily_plan_blocked` /
`smaily_credentials_rejected` / `smaily_down`). A stated refusal is raised at the
NEXT health check instead of after the hour's grace — Smaily already gave the
answer and it reads the same in an hour; only the "might be a blip" case still
waits. Nothing is stored: the cause is recomputed each run, so the moment Smaily
answers again the stamp and the notice go, with nothing to re-enter.

**Rationale:** the plan case is not indistinguishable from bad credentials, so
the honest-but-vague "one message naming both causes" fallback was not needed —
but the ORDER matters and is stated in the message: while a package blocks the
account the credentials cannot be checked at all, so the plan message never
implies the credentials are known good.

**Alternatives:** teaching RetryPolicy about plan blocks (rejected — a 403 is
already correctly permanent there; this is a messaging layer, and duplicating
the classification into the retry decision would give one fact two owners);
keeping `test_connection(): bool` alongside the new method (rejected — it would
have had no callers left); an extra "wrong subdomain" message for the 404
(rejected — the merchant typed the subdomain in the same credential form).

**Demonstration:** live against the freemium account through the REAL plugin
path (`bin/walk-pro1686-plan-block.php`, run inside wp-env with the credentials
piped over STDIN): `connection_check: plan_blocked`, the Test-connection button's
answer, and the rendered admin notice naming the package.
`tests/Integration/SmailyPlanBlockedNoticeTest.php` replays that exact 403/227
answer through the real health check plus the credentials and outage cases, and
covers the restore half with the transport answering normally again (3 of its 4
cases fail against the pre-fix code). Restoring the plan on the live account is
human acceptance — the plugin half is proven by the restore test.

**Relationships:** sits on top of PRO-1685 (a plan-blocked 4xx lands in that
policy's permanent branch, which stays correct and untouched); the Event Log
reason a failed row carries now includes `(Smaily code 227)` so the same
refusal reads the same way in the log.

### PRO-1710 — A recommendation id is a UUID or it is not attribution: validated where it enters AND where it leaves

**Context:** the engine validates an order's `smaily_rec_id` as
`z.string().uuid()`, **per order** (D6) — one order carrying anything else is
permanently rejected with an `errors[]` entry while its batch mates go through.
The plugin's landing capture deliberately accepted any bounded id token
(`^[A-Za-z0-9._-]{1,64}$`, F3-46: "don't hard-fail if the engine's rec_id shape
ever changes"), and the mock never inspected the field. So a visitor landing
with a hand-typed, truncated or crafted `?smaily_rec=` value got it cookied,
stamped onto their order at checkout, and **that order never reached the
engine** — silent loss of a real purchase, with every gate green.

**Decision:** one definition of the shape (`Smaily\Connect\Smaily\RecEngine\
Support\RecId`, the engine's zod-v3 uuid regex verbatim) enforced at BOTH ends
of the cookie's life:
- **capture** — `LandingCapture` (and its JS twin `RecEngineClient::
  captureUrlParams`, which writes the same cookie) refuses to cookie a non-UUID
  `smaily_rec`; the landing is then simply un-attributed. The `utm_content`
  fallback already required this shape and keeps it, now from the same source.
- **send** — `OrderPayloadBuilder` omits a non-UUID `_smaily_rec_id` from the
  wire object and lets the order ingest un-attributed (a WP_DEBUG line records
  the drop; the F3-44 exchange on the queue row shows what actually went out).
  The stored meta is NOT rewritten — merchant data is left as it is.
The mock now returns the same per-order D6 error the live route does
(`field: smaily_rec_id`, `message: "Invalid uuid"`), so this class of drift
can't hide again. `smaily_vt` is untouched: the visitor token is an opaque
engine string, not a UUID.

**Rationale:** the send-side half is not redundant with the capture-side fix —
a cookie already sitting in a shopper's browser on a live store cannot be
reached by a plugin release, so without it every such shopper's NEXT order
would still be rejected. Between the order and the attribution, the order wins:
attribution is an optimisation, an ingested order is the data the whole product
runs on. And the capture-side half is not redundant with the send-side one
either — it stops the junk value from ever being persisted, so nothing
downstream has to know about it.

**Alternatives:** validating only at capture (rejected — leaves live stores
poisoned indefinitely); validating only at send (rejected — keeps writing
known-bad cookies and shifts the fix onto every future consumer of that
cookie); sending the junk value and letting the engine reject the order
(rejected — that IS the bug); stripping the bad meta off the order (rejected —
rewriting a merchant's order records to work around a wire constraint is a
bigger promise than the problem needs); waiting for the contract to type the
field (PRO-1713 — the live route is the authority, and waiting would leave
orders failing meanwhile).

**Demonstration:** `RecEngineOrdersTest` drives the real chain on the running
store — the server-side landing capture, a real WooCommerce order, the real
classic-checkout stamping and a real flush against the mock: a junk landing
leaves no meta and the order ingests un-attributed, a genuine UUID rides through
to the wire exactly as before, and a junk value ALREADY on order meta is dropped
at send. The two junk cases fail against the pre-fix code (the second one comes
back `failed` — the now-honest mock D6-rejects the order, which is precisely the
production symptom). A fourth test posts the raw shape through `Client::
ingest_orders` to keep the mock pinned to the engine's validation.

**Relationships:** narrows F3-46 (the capture that was deliberately permissive);
mirrors the PRO-1498/PRO-1506 pattern of repairing at BOTH enqueue and flush
time for the same reason (a row/cookie captured before the fix can't heal
itself). §6 browse carries the same engine constraint and is out of scope here —
the plugin's JS never puts a rec id on a browse event, and since PRO-1712 the
`/relay` whitelist strips a client-supplied one too.

### PRO-1633 — A return is a property of the ORDER, re-derived on every send; a line comes back only in full

**Context:** contract v1.8.0 §5 documents three line-level return fields
(`returned_at`, `return_reason_standardised`, `return_reason_raw`) and the
engine derives a FULL refund itself from `status: "refunded"`. A WooCommerce
**partial** refund is the case nothing else covers: it fires no webhook and
does not change the order status at all, so an order with one line sent back
looked identical to one nobody returned. §5 also warns that items are **fully
replaced on re-ingest** — a later sync that omits `returned_at` ERASES the
return, and the engine has no way to notice.

**Decision:**
- **Derive, never carry.** `OrderPayloadBuilder` reads the order's own refunds
  (`WC_Order::get_refunds()`) on every build and stamps the return fields from
  them. The refund event supplies nothing but a nudge — the queue row stays
  payload-less. The sender obligation is then satisfied by construction: the
  live hook, a flusher retry and the order backfill all build through this one
  class at send time, so every future sync re-derives and re-sends the same
  return. It also means a store that switches this on later carries its
  historical returns automatically, on the next sync of each order — no
  backfill of refunds was written, and none is needed.
- **One new binding.** `woocommerce_order_partially_refunded` → an
  `order.upsert` row (`OrderHookHandler::on_order_partially_refunded`,
  registered in Bootstrap). A FULL refund needs nothing: it flips the order to
  `refunded`, which the existing status hook already resyncs, and the engine
  derives all-lines-returned from the status either way.
- **A line is returned only when the WHOLE quantity has come back.** Refunded
  quantities accumulate across several refunds; the refund that completes the
  return supplies the date (IsoDate `Z` — F3-21) and the reason.
- **`return_reason_raw` is WooCommerce's merchant-side refund reason**,
  trimmed, capped at 500 chars, omitted when blank.
  **`return_reason_standardised` is never sent** — WooCommerce has no
  structured return taxonomy anywhere, and §5 is explicit that keyword-guessing
  free text into the enum is worse than sending nothing.

**Rationale:** the alternative — treating the refund event as the source and
storing what we sent — would need its own persistence, would drift from the
merchant's actual refund records, and would break the moment a refund is
edited or deleted in the admin. Reading the order is the only source that
cannot disagree with itself. The cost is one extra `get_refunds()` query per
order built (WC caches it per order); acceptable next to the order + items
reads already happening.

**The one-of-three question, and why it is answered conservatively:** the
contract types `returned_at` per LINE and offers no per-quantity mechanism, so
a line of qty 3 with 1 refunded has no honest representation. Its consumers
(180-day same-SKU suppression, the "was it kept?" trigger preconditions) read
the field as "the customer does not have this" — which is false while the
customer kept 2. So a partly-refunded quantity stays **kept**, and the signal
is under-reported rather than wrong. Whether the engine would rather have the
line flagged (or gain a `returned_qty`) is an open question for the engine
team, filed alongside this work; it is a widening, not a reversal, so it does
not block. An **amount-only** refund (no line quantity — a goodwill or
price-adjustment refund) likewise marks nothing: the money moved, the goods did
not.

**Best-effort, by design:** stores that process refunds outside WooCommerce
send nothing here, and §5 says NULL means "kept", which is the correct default.
A refund DELETED in the admin still leaves its return standing in the engine
until the order's next sync re-derives it — accepted (no
`woocommerce_refund_deleted` binding was added), because the derivation
self-heals on any later sync and a deleted refund is rare.

**Demonstration:** `RecEngineOrdersTest` drives the real chain on the running
store — a real `wc_create_refund()` partial refund, the real
`woocommerce_order_partially_refunded` binding, the real flusher, the mock
engine: the order status is asserted unchanged, the refunded line arrives with
a `Z`-form `returned_at` + the reason, the untouched line arrives clean, and a
LATER status-driven sync still carries the same `returned_at` (the erase case).
The mock now rejects a non-`Z` `returned_at` per order, like every other engine
datetime. `bin/walk-pro1633-return-signals.cjs` mirrors it against the live
sandbox engine.

**Relationships:** implements contract v1.8.0 §5 (PRO-1597); extends the F3-21
IsoDate rule to the first datetime we put on a line; the derive-at-send-time
shape is the same reasoning as F3-42's "read the status fresh at flush".

### PRO-1767 — Attribution capture loads whenever the store is CONNECTED, as its own tiny bundle

**Context:** the browser-side attribution writer is consent-independent
(PRO-1388) and exists precisely because a full-page cache serves a campaign
landing without ever executing PHP, so `LandingCapture` never runs on it. But
the script carrying that writer — the full browse runtime `sc-runtime.js` — is
only enqueued when browse tracking is ON. A connected store with browse
tracking off (the default) plus a page cache therefore had **no attribution
writer at all**: PHP was skipped by the cache, and the JS was never loaded. The
sibling Shopify and Magento plugins write these cookies client-side
unconditionally for exactly this reason (2026-08-04 cross-plugin assessment).

**Decision:**
- **A second, attribution-only storefront bundle** — `public/js/landing.ts` →
  `dist/public/js/sc-landing.js` — enqueued by `StorefrontBeacon` when the
  engine is connected and the full runtime is NOT loaded. It does one thing:
  read the campaign params, write the three first-party cookies, strip the
  params. No transport, no consent surface, no session cookie; its boot blob
  (`window.smailyConnectLanding`) carries cookie names, param names and TTLs and
  not even the `/relay` URL. ~1.2 kB.
- **Mutually exclusive with the runtime**, decided at enqueue time
  (`is_attribution_only_enabled()` returns false whenever `is_enabled()` is
  true). When browse tracking is on, the runtime's own
  `captureUrlParams()` does the capture as before — so the cookies are never
  written twice and neither writer needs to know about the other.
- **One implementation, shared:** `public/js/lib/attribution.ts` holds the
  capture (incl. the PRO-1710 UUID check on the rec id) and the cookie write;
  `RecEngineClient.captureUrlParams()` now delegates to it. Two writers of the
  same cookies must not be able to drift.
- **Its gate is LandingCapture's, not the beacon's:** connected + the
  `smaily_connect_capture_attribution` master switch, and no WooCommerce check
  (a campaign link can land on any page). Browse telemetry keeps its own
  unchanged gate — the browse toggle plus marketing consent.

**Rationale:** the alternative was to load the full runtime on browse-off stores
with a "capture only" flag. Rejected: it ships an event pipeline, a consent
reader and a proxy URL to a store that has switched all of that off, which is
both a bigger ad-block target and a much harder thing to defend when a merchant
asks what the script does. A 1.2 kB writer with no send path is answerable in
one sentence. Honouring the existing master switch (rather than adding a second
one) means a merchant who deliberately turned attribution cookies off does not
silently get a new writer from an update.

**Build consequence (a trap worth naming):** because both bundles import the
shared module, they must be built in SEPARATE vite passes (`--mode landing`,
chained from every `build*` script). Verified 2026-08-05: in one pass Rollup
hoists the shared module into `dist/shared/attribution-<hash>.js` and gives BOTH
bundles a top-level `import` — at which point neither loads as the classic
`<script>` `StorefrontBeacon` enqueues, silently breaking browse tracking too.

**Demonstration:** on the running dev store (connected, browse tracking off) a
storefront request returns the `sc-landing.js` tag and the
`window.smailyConnectLanding` blob, with no `sc-runtime.js`. The integration
suite pins the whole enqueue matrix (off+connected → writer only; on+connected →
runtime only; disconnected → neither; master switch off → neither), and vitest
covers the shared capture standalone, including the junk-rec-id refusal.

**Relationships:** completes F3-46 / PRO-1388 (the server-side capture stays as
the non-cached path — the two are deliberately redundant); keeps PRO-1710's UUID
rule in the one place both bundles read; keeps the F3-41 naming rule (`sc-`
prefix, no tracker keyword) for the new browser-visible file; does not touch
F3-50 browse-consent gating in any way.

### PRO-1712 — the deprecated browse attribution hints leave the `/relay` whitelist

**Context:** contract v1.7.0 deprecated `smaily_rec_id` and `smaily_ctx` on
browse events to accept-and-ignore — the engine dropped both columns
(migrations `0080`/`0081`) and deleted the 4th-priority attribution fallback
they fed, which had never matched a purchase in production. Both are
client-originated (read from the cookies a campaign landing writes) and
therefore spoofable with no server-side verification: exactly the class
PRO-1486 closed for `customer_email`, left open here because the engine still
accepted the fields and nothing was known to break. That was the last argument
for keeping them.

**Decision:** `smaily_rec_id` and `smaily_ctx` are removed from
`BeaconEndpoint::EVENT_FIELDS`. A client-supplied value on the `/relay` POST
is dropped by the whitelist like any other unrecognized field, before the
batch is forwarded.

**Rationale:** the engine no longer persists or consults either field, so
forwarding a client-supplied value buys nothing and keeps a spoofing surface
open for free. Our own JS client never sent them (`rec-engine-client.ts`
`enrich()`, F3-49), so normal browse traffic is byte-identical on the wire —
the strip only removes values a hand-crafted request injected. Rec attribution
is untouched: it rides the ORDER path (`smaily_rec_id` on §5, from the cookies
`LandingCapture` writes and `HookHandler` stamps onto the order), never the
browse event.

**Scope:** the same caveat PRO-1486 records still holds — this whitelist
governs the browse-event POST only; a future recommendations-GET proxy needs
its own field handling rather than reusing `validate_batch()`.

**Tests:** unit
(`BeaconEndpointTest::test_deprecated_attribution_hints_are_stripped`) pins the
whitelist strip; integration
(`RecEngineBrowseProxyTest::test_deprecated_attribution_hints_never_reach_the_engine`)
proves over the real `/relay` path that neither field reaches the mock engine
while the event still forwards with its legitimate `smaily_visitor_token`.

**Relationships:** PRO-1486 (the precedent and the follow-up this closes),
F3-49 (client-side data-minimization — this is its server-side enforcement for
the last two fields), PRO-1524/PRO-1465 (the engine-side deprecation that made
the fields worthless on the wire).

### PRO-1878 — `checkout_complete` flushes immediately, everything else keeps the 30s window

**Context:** browse events buffer in memory for `batchWindowMs` (30s) or until
`pagehide` fires, where `flushOnUnload()` sends them with `sendBeacon`. That is
fine for every page a shopper stays on — but `checkout_complete` fires on the
order-received page, which shoppers close within seconds. The event therefore
depended almost entirely on the pagehide path, which is best-effort (a killed
tab, a blocked/failed `sendBeacon`, a browser that never fires the handler), and
the engine measured only ~52% of orders producing a `checkout_complete`. That
asymmetry against `checkout_start` — which is unaffected, shoppers linger on
checkout past the timer — is the one genuine plugin-side loss in the PRO-1878
investigation.

**Decision:** `RecEngineClient.track()` sends immediately (the existing
`flush()`) when the tracked event is `checkout_complete`
(`IMMEDIATE_FLUSH_EVENT`), instead of scheduling the batch window. All other
event types are untouched.

**Rationale:** the buffer exists to avoid a POST per browse event on a browsing
session; the order-received page is the end of the session and produces exactly
one event, so batching buys nothing there and costs a measurable share of the
conversions. Reusing `flush()` rather than adding a second transport keeps the
consent gate (`flush()` still drops the buffer when consent is absent — no
consent still means the event never leaves the browser) and the retry/in-flight
semantics identical. No double-send: `flush()` clears the pending timer and
takes the buffer synchronously before its first `await`, so a `pagehide` landing
mid-flight finds an empty buffer and sends nothing; the in-flight request is
`keepalive`, so unload does not kill it.

**Alternatives:** (a) `sendBeacon` on `checkout_complete` — same reliability
question we are trying to escape and a second transport to keep consistent; (b)
a shorter window for the whole client — degrades batching on browsing pages for
one event's benefit; (c) covering `checkout_start` too — not needed, shoppers
stay on checkout well past 30s, and it would trade batching away for nothing.

**Tests:** vitest (`rec-engine-client.test.ts`) pins the immediate POST, that it
takes the whole buffer with it, that no second send happens on pagehide or the
timer, that `checkout_start` still waits the full 30s, and that a
consent-less `checkout_complete` still sends nothing.

**Scope:** capture-side only. PRO-1878 stays open — the remaining question (how
the engine counts/joins these events) is engine-side.

**Relationships:** F3-24 (browse-beacon architecture and the batch window this
narrows), F3-50 (the consent gate, unchanged), browse browser-timing is still
not live-walk-coverable (the CLAUDE.md note stands — this changes WHEN the
buffer is sent, not when the browser fires the event).

### PRO-1896 — Both attribution writers accept exactly the same values, and an over-cap cookie is dropped, never trimmed

**Context:** the two writers of the same three first-party attribution cookies
disagreed. `LandingCapture::resolve()` (PHP) shape-checks all three —
`RecId::is_valid()`, `/^vt_[A-Za-z0-9]{1,64}$/`, `/^[A-Za-z0-9._-]{1,64}$/` —
while `public/js/lib/attribution.ts` only ported the rec-id UUID rule
(PRO-1710), taking `smaily_vt` / `smaily_ctx` at any value and any length.
PRO-1767 turned that asymmetry from theoretical into reachable: `sc-landing.js`
now loads on browse-OFF stores, whose only writer used to be the strict PHP
one. A crafted landing URL could plant an arbitrary/oversized value in a
30/365-day cookie that rode to order meta and onto the §5 orders wire (delta
security audit 2026-08-07, Medium §1).

**Decision:** (a) `attribution.ts` gets `VISITOR_TOKEN_PATTERN` /
`CONTEXT_PATTERN` mirroring the PHP regexes verbatim, applied through the same
per-slot `isValid` the rec-id already used — an off-shape value is refused the
cookie while the param is still stripped from the URL. (b)
`HookHandler::save_attribution_cookies_to_order()` caps each cookie at the
longest value its shape can hold (`ORDER_META_MAX_LENGTH`: rec_id 36,
visitor 67, context 64, session 64) and **drops** anything longer.

**Rationale:** the capture fix cannot reach a cookie already in a browser — a
value planted before this ships outlives it by the cookie's 30/365-day TTL, so
the send path needs its own backstop (the same two-ended reasoning as
PRO-1710). Dropping rather than truncating, because a trimmed token is a
plausible-looking wrong value: it would reach the engine as if it were real
attribution, where an absent field is simply an un-attributed order. The
session id gets the generous 64 bound rather than an exact UUID length because
nothing shape-checks it anywhere; the cap is there to bound a planted value,
not to type the field.

**Alternatives:** (a) validate on the send side only — leaves the oversized
`Cookie` header on every subsequent request to the store, the audit's second
impact; (b) truncate to the cap — see above; (c) share one regex source across
PHP and TS — there is no build step joining them, so the pattern is duplicated
with a comment naming its twin, exactly as `RecId` / `REC_ID_PATTERN` already
are.

**Relationships:** PRO-1710 (the rec-id half of the same rule, and the
drop-don't-guess precedent), PRO-1767 (why the gap became reachable), F3-46
(the server-side capture this mirrors).

### PRO-1942 — The orders wire shape-checks all four attribution signals at send time, from one shared definition

**Context:** `OrderPayloadBuilder::build()` validated `smaily_rec_id` (PRO-1710)
and forwarded `smaily_visitor_token` / `smaily_rec_ctx` / `session_id` to the §5
orders wire on a bare non-empty check. PRO-1896 bounded what can be *stamped*
onto an order from now on, but a value stamped before it shipped is already on
orders in the queue, and those retry through the flusher for the queue's
lifetime — the send path had no shape rule of its own for three of the four.

**Decision:** a new `Support\AttributionShape` holds the visitor-token and
context regexes (the LandingCapture definitions, moved not copied — LandingCapture
now calls it), plus `is_session_id()`, and `OrderPayloadBuilder` checks all three
at send time. An off-shape value is **omitted** from the payload, with a
shape-only DebugLog line; the rest of the order sends normally and the meta is
left on the order untouched.

**Rationale:** the exact twin of the PRO-1710 treatment, and the same
one-definition-two-ends move `RecId` already made — the drift this closes existed
precisely because the shapes lived only in the capture path. Omit rather than
truncate for PRO-1896's reason: a trimmed token reaches the engine as if it were
real attribution. `session_id` keeps PRO-1896's deliberately generous bound (the
context charset, 64) rather than the UUID its producers actually emit — nothing
has ever enforced a shape on it, and a rule stricter than the consumer's would
drop values the engine accepts.

**Alternatives:** (a) duplicate the two regexes in the builder — rejected, that
is the drift being fixed (the PHP↔TS duplication in PRO-1896 stands only because
no build step joins those two languages); (b) sanitise/truncate to shape — see
above; (c) rely on the PRO-1896 order-meta cap alone — it is length-only, cannot
reach values already stamped, and length is not shape.

**Relationships:** PRO-1710 (the rec-id half, and the pattern this copies),
PRO-1896 (the capture-side cap and the 64-char session bound), F3-46
(LandingCapture, the capture-side owner of these shapes).

### PRO-1949 — Source maps are stripped from the release ZIP; the readable source is the public GitHub repo (DECIDED by Erkki 2026-08-10)

**Context:** PRO-1781 kept the TypeScript sources out of the ZIP
(`publicDir: false` + `/public/js*`, the counterpart of `/admin/src*`), and
justified it with "the readable source stays in the bundles' source maps". But
a vite map embeds the very same sources via `sourcesContent` —
`dist/admin/admin.js.map` alone is ~966 kB of a ~1.14 MB ZIP — so the
exclusions saved almost nothing and shipped the source anyway, in a shape no
merchant benefits from.

**Decision:** strip them. `.zipignore` excludes `*.map`, and `composer run
package` runs `package:strip-map-refs` over the staging tree (a `sed` that
deletes the `//# sourceMappingURL=` trailer line from every staged `*.js`), so
the shipped bundles carry neither the maps nor a dangling reference to them.
`vite.config.ts` keeps `sourcemap: true` — local builds are unchanged and stay
debuggable; the strip happens only at packaging.

**Rationale:** the wordpress.org expectation of readable source is met by the
public GitHub repo (a link a reviewer can read, diff, and clone), not by a
966 kB blob a merchant downloads with every update. Doing it at packaging
rather than at build time is what makes it unforgettable: no `SMAILY_RELEASE=1`
flag to remember, and a release cut can never accidentally ship maps because it
built the bundles a different way.

**Alternatives:** (a) keep the maps as the reviewer-readable source — rejected
above, it is a ~6× ZIP for an audience of approximately nobody; (b) build the
release with `sourcemap: false` — same ZIP, but it needs a release-only flag
that is exactly the kind of step that gets skipped, and dev builds lose maps if
the flag becomes the default; (c) exclude `*.map` in `.zipignore` only —
leaves every shipped bundle ending in a trailer pointing at a file that is not
there.

**Relationships:** PRO-1781 (the source exclusions this completes, and the
"maps carry the source" claim it supersedes), F3-41 (the shipped bundle names
the strip runs over).

### PRO-2277 — The release workflow builds the shippable ZIP; on the official repo the CI build is the authoritative one, tagged with the plain version (2026-09-03)

**Context:** the plugin ZIP is not `composer run package` — it is a six-step
build (admin vite pass, the chained landing pass, `wp-scripts` blocks, the i18n
catalogs incl. the fixed-name admin-bundle JSON, a `--no-dev` vendor tree,
then the package). `release.yml` ran three of those steps: no vite build at
all, a `compile-translations` that calls a bare `wp` not on PATH (wp-cli is at
`vendor/bin/wp`) and would not produce the admin-bundle JSON name anyway, and
the DEV vendor tree. Every release so far was therefore cut by hand from the
sequence in CLAUDE.md. That was liveable while releases went to a fork; it is
not liveable for the official `sendsmaily` repo, where the publish path is
"create a release → CI attaches `smaily-connect.zip` → `./release.sh -u
sendsmaily` pushes that asset to wordpress.org SVN" — a broken asset there
installs silently broken on merchant stores.

**Decision:** `release.yml` now runs the documented local sequence end to end
and gates the result with `bin/verify-release-zip.sh`, which asserts the
required build outputs are present, the development-only material is absent, no
shipped bundle keeps a `sourceMappingURL` trailer, the archive root is
`smaily-connect/` (what `release.sh` copies into the SVN trunk), and the
version is internally consistent. On the official repo the CI ZIP is the
authoritative artifact. **The release tag there is the PLAIN version
(`3.11.2`), no `v` prefix** — that is what `release.sh` builds its download URL
from and what upstream history uses; a tag that does not equal the plugin
header version fails the run before anything is uploaded. A leading `v` is
tolerated so the fork's own `v3.x` habit cannot break a run, but the plain form
is the convention. `workflow_dispatch` builds and verifies the same ZIP and
uploads it as a workflow ARTIFACT only — a dispatch never writes to a release.

**Rationale:** the failure mode being closed is silent, not loud — a ZIP
missing `dist/admin/admin.js` installs fine and simply has no admin UI, and a
ZIP carrying the dev vendor tree ships phpunit to merchants. A gate that runs
in the same job that produces the artifact is the only place that cannot be
skipped. Keeping the verification in a script rather than inline YAML is what
lets the local sequence be checked with the identical rules, which is how the
CI build is held to the local one.

**Alternatives:** (a) keep building locally and hand the maintainer a ZIP —
rejected, it makes every official release depend on one machine's toolchain and
on remembering six steps in order; (b) inline the checks in the workflow —
rejected, they would then only ever run in CI, and the local sequence (still
the reference build) would have no gate; (c) run `composer run
compile-translations` in CI — rejected, it does not produce the admin-bundle
JSON under the name WordPress requests, so the admin UI would silently fall
back to English.

**Relationships:** PRO-1949 (the `sourceMappingURL` strip the verifier
enforces), PRO-1781 (the source exclusions it enforces), PRO-1196 (the
wordpress.org publish path this unblocks).

### PRO-2281 — The official repository is the working repository; the fork is archived history (2026-09-03)

**Context:** the v3 rewrite was built in the fork
`erkkimarkus/smaily-wordpress-plugin` because there was no write access to
`sendsmaily/smaily-wordpress-plugin`. Maintainer access was granted 2026-09-02
and PR #135 folds the rewrite into the official repository, so from the merge
on there are two addresses for the same plugin with no rule saying which one is
real. That ambiguity has already cost us once at merchant level: `readme.txt`
still pointed at the fork's releases page for the 1.x/2.x version history
(PRO-2280).

**Decision:** after PR #135 merges, the official repository's `main` is the
working repository — development, issues, releases. The fork is archived
read-only as history (its releases stay readable; nothing new is cut there).
Releases are created in the official repo under a **plain** version tag
(`3.11.2`, no `v` — PRO-2277), the release workflow builds and verifies the
ZIP, and `./release.sh -u sendsmaily` publishes it to wordpress.org. The first
official release is a fresh 3.11.2, not a re-tag of the fork's 3.11.1 (main has
behaviour changes since that tag). The developer docs follow: `README.md`'s
clone address and distribution sentence, the CLAUDE.md release runbook and
`docs/DEVELOPER.md` name the official repo, and the fork flow (`v`-prefixed
tag, `--repo erkkimarkus/…`, a hand-uploaded ZIP) is marked HISTORY rather than
offered as an alternative.

**Rationale:** one canonical address. wordpress.org distribution is the goal
(~2,000 existing installs update from there), and the SVN publish reads the
official repo's release asset — a second live address invites exactly the drift
the readme showed, where a merchant or a developer is sent to a repository that
no longer receives the work.

**Alternatives considered:** (a) keep developing in the fork and merge upstream
per release — rejected, it makes every release a merge and keeps two histories
diverging for no benefit now that access exists; (b) delete the fork — rejected,
its releases and issue history are the record of the rewrite, so read-only
archive rather than removal.

**Relationships:** PRO-1196 (the upstream merge this closes the loop on),
PRO-2277 (the CI build + plain tag it depends on), PRO-2280 (the readme address
fix that surfaced the two-address problem), F3-35 (the `Update URI` opt-out that
was removed ahead of the merge).


### PRO-2286 — The connection test reuses the stored password, but only for the account it is stored for (2026-09-04)

**Context:** the PRO-2285 upgrade rehearsal found that a store upgraded from the
wordpress.org 2.0.0 package cannot get past the wizard's Step 1. Its legacy
credentials carry over and keep syncing, but
`smly_plus_default_connection_verified` is only ever written by a new-code
save, so `smailyConnected` hydrates false and the merchant lands on an empty
password field — and the
password is never sent to the browser. Steps 2-6 stay locked until "Test
connection" succeeds, and `TestConnectionEndpoint` rejected an empty password.
The save path had always treated an empty inbound password as "keep the stored
one"; only the test had not, so a merchant without the API password had to mint
a new Smaily API user to finish setup. ~2,000 stores take this path when 3.11.2
reaches wordpress.org.

**Decision:** `password` becomes optional on the test-connection route. When it
arrives empty, the endpoint tests with the password stored for the DEFAULT
account — but only when the submitted subdomain and username still equal that
stored set; otherwise it answers exactly as before ("Subdomain, username, and
password are required."). A stored password Smaily no longer accepts produces
the ordinary rejection response. `EnvDetector::saved_settings()` publishes one
new boolean, `smailyHasStoredPassword`; the wizard enables the Test button with
an empty password only while that flag holds and the account on screen is still
the hydrated one. The upgraded store is NOT auto-marked connected, and the save
path is unchanged.

**Rationale:** the subdomain + username match is what keeps the fallback
honest — a merchant typing a different account into the fields is testing THAT
account, and must never be told it works because the previous one still does.
Auto-marking an upgraded store as "connected" without a test was rejected for
the same reason in reverse: the whole value of Step 1 is that the merchant is
shown, before anything is saved, that the credentials the store will actually
use authenticate today. Legacy credentials can be stale (rotated in Smaily, on
a package that lost API access — PRO-1686), and silently unlocking Steps 2-6 on
their mere presence moves that discovery to the first failed sync.

**Alternatives considered:** (a) mark an upgraded store connected when a
credential set exists — rejected, above: it trades a real check for a guess;
(b) send the stored password to the browser so the field can be pre-filled —
rejected, secrets never reach the browser (CC-3); (c) accept the fallback for
any submitted subdomain/username — rejected, it would report success for an
account that was never tested.

**Relationships:** CC-3 (secrets never leave the server), PRO-2285 (the
rehearsal that found it), PRO-1686 (why a failed test needs its own reason),
PRO-2287 (the other rehearsal finding, the daily sync gate).


### PRO-2287 — The daily contact-sync tick waits for the setup wizard (2026-09-04)

**Context:** the PRO-2285 upgrade rehearsal found that a store upgraded from
2.0.0 makes a real Smaily API call before the merchant has finished the wizard:
the legacy credentials carry over, and the daily `smly_plus_contact_sync` tick
ran its reconcile + contact-refresh against them with no gate. The live
checkout/registration path is gated (`HookHandler::gate_closed()` — the legacy
hooks own live sync until Finish), and the legacy daily mass-send is retired on
upgrade by design (F3-53/F3-48.3), so the daily tick was the one scheduled
outbound caller on an unconfirmed store — and `docs/MIGRATION.md` promised
"contact sync continues uninterrupted" without saying which sync.

**Decision (Erkki, 2026-09-03):** `Bootstrap::on_contact_sync_tick()` returns
early — logging `[smaily-connect contact.sync] skipped: setup not completed` —
while `smly_plus_setup_completed` is false, the same option the live hooks read.
Both steps are skipped, so no Smaily call and no contact-refresh scheduling
happen. The daily catch-up resumes on the next tick after the wizard is
confirmed. `docs/MIGRATION.md` and the merchant docs site now say exactly that:
live syncing continues through the upgrade, the daily catch-up resumes after
setup is confirmed.

**Rationale:** the gate belongs at the tick, not inside `ContactReconciler` or
`BackfillJob` — both are also driven by the wizard's own Backfill UI and the
REST route, where the merchant has explicitly asked for the work. An upgraded
store should make no scheduled outbound call until its operator has seen and
confirmed the settings that call is made under; that is the same promise Step 1
makes about credentials (PRO-2286).

**Alternatives considered:** (b) keep the daily tick running on the carried-over
credentials — rejected: it syncs on settings the merchant has not reviewed and
contradicts the "legacy owns sync until Finish" split; (c) bridge the legacy
daily catch-up for the window — rejected: that is exactly the F3-47 site-locale
language clobber F3-48.3 retired, and it would resurrect a retired code path for
a window measured in days.

**Relationships:** F3-48.3 (the tick stopped bridging the legacy mass-send),
F3-53 (why a retired legacy cron must find nothing to fire), PRO-2285 (the
rehearsal that found it), PRO-2286 (the other rehearsal finding), P1 #1 /
`HookHandler::gate_closed()` (the live-path gate this mirrors).


### PRO-2292 — The setup-completed flag has one accessor, `SetupState::completed()` (2026-09-04)

**Context:** the wizard-completion flag `smly_plus_setup_completed` gated six
things — the live checkout/registration sync (`HookHandler::gate_closed()`), the
cart tracker, the abandoned-cart sweeper, the Settings screen redirect, the
`EnvDetector` hydration and, since PRO-2287, the daily contact-sync tick — and
every one of them read the option raw with its own spelling. `HookHandler` kept
the key in a *private* const, so nothing else could reuse it even if it wanted
to. That is the shape of the PRO-1742 bug: a gate reading a key nothing ever
wrote, invisible until the one merchant it bites reports it.

**Decision:** one public accessor, `Smaily\Connect\Settings\SetupState::
completed()`, with `SetupState::OPTION_SETUP_COMPLETED` as the single definition
of the key. Every gate calls it; the wizard's Finish route
(`SettingsEndpoint::save_finish()`) writes through the same constant.
`HookHandler`'s private const is removed (nothing outside referenced it). No
behaviour change: the accessor is exactly the `(bool) get_option( …, false )`
every site already ran — all six agreed on that truthiness, so nothing had to be
unified.

**Rationale:** the `ContactSyncMode::sync_enabled()` precedent, applied to the
flag's twin. A gate that spells its own key is one typo away from being a gate
that never closes, and the miss is silent in both directions.

**Alternatives considered:** (b) make `HookHandler::OPTION_SETUP_COMPLETED`
public and have the others import it — rejected: a WooCommerce hook handler is
the wrong owner for a wizard-state key, and `Bootstrap`/`EnvDetector` would then
depend on it for no reason; (c) leave it — rejected: PRO-1742 is the cost of
leaving it.

**Relationships:** PRO-1742 (the precedent and the same bug class), PRO-2287
(the newest of the read sites), P1 #1 / `HookHandler::gate_closed()` (the
gate whose const this replaces).


## How to keep this document going

For every new significant technical decision (as part of a sub-PR plan or
discovered along the way):

1. Add a **new entry** in the relevant category (F3-22, F3-23, ...)
2. Follow the 5-field form: Context / Decision / Rationale / Alternatives /
   Relationships
3. Keep it **short** (5-15 lines per decision) — a log entry, not a full ADR
4. ~~At the end of Phase 3, refine~~ — DECIDED (2026-06-11): keep the single
   `DECISIONS.md` file; the ADR-per-file split was rejected (the F-numbered
   log is the working format both agents and humans navigate by)

**What's likely to be added later in Phase 3** (F3-19/20/21 the 3.3 customers
milestone, A-filter, datetime; F3-22 orders + status mapping; F3-23 N-7
AbstractD6Flusher + W2 drift; F3-24 browse-beacon architecture; F3-25 backfill
architecture — all above). F3-28 (GDPR) and F3-29 (Step-4 activation) are now
written in full above.

In each sub-PR's planning phase: "is this decision worth adding to DECISIONS?"
Rule of thumb: **if the rationale requires more than one sentence**, add it.
