# Upstream merge proposal — fold the Smaily Connect rewrite into the wordpress.org plugin

**Audience:** the Smaily team that owns `sendsmaily/smaily-wordpress-plugin` and the
`smaily-connect` wordpress.org listing.
**From:** the fork (`erkkimarkus/smaily-wordpress-plugin`), at **v3.0.0 (GA)**.
**Status:** proposal / decision request. Nothing irreversible has been done — the fork
is currently distributed only via GitHub Releases. This document makes the case and
lists what it takes to land; **the decision is Smaily's.**

---

## Current state (2026-07-23)

The fork is at **v3.9.0**; the GA line has been stable since **3.0.0** (2026-06-25) and
a real-merchant pilot (MiuMjau) has been running on it since. The code-side punch-list
below is **complete** except the two items that are Smaily-owned by nature (#6
wordpress.org submission mechanics, #7 pilot sign-off — see the checklist). Notably,
the `Update URI` guard (item 2 below) that used to keep wordpress.org from overwriting
the fork was **removed in v3.9.0** (2026-07-23) — the fork is no longer forcing a
GitHub-only update channel by header; an existing install now transitions onto the
wordpress.org update channel automatically, with no separate cutover step, the moment
sendsmaily publishes a release version ≥ what's installed.

**Update (2026-09-02):** maintainer access on `sendsmaily/smaily-wordpress-plugin` was granted, so the remaining mechanics are Smaily-side: merge PR #135, cut a fresh **3.11.2**, and create the release under a **plain** version tag (`3.11.2`) — the release workflow now builds and verifies the shippable ZIP itself, and `release.sh` pushes that asset to the wordpress.org SVN.

---

## TL;DR

The fork is a **rewrite of the WordPress plugin** that **preserves the entire 1.x
feature set and adds** a modern admin UI, the Smaily Campaign Intelligence integration,
a durable ingest pipeline, GDPR tooling, real test coverage, static analysis, and
stronger credential encryption. Upstream `2.0.0` is, by its own changelog, the 1.x line
plus a minimum-version bump — not a rewrite.

Because the fork is a **strict superset** of what is on wordpress.org today, the
sensible path is **not a git merge** (the two are parallel codebases) but a **wholesale
takeover**: upstream adopts the rewrite as the next major version (`3.0.0`, which already
sits monotonically above `2.0.0`) and publishes it to wordpress.org. This proposal lists
the prerequisites so that takeover is clean and low-risk for the ~2,000 existing installs.

---

## What each "2.0" actually is

The two releases share a major-version number and the `smaily-connect` slug but are
**unrelated codebases** (full detail: [`audits/UPSTREAM_COMPARISON.md`](audits/UPSTREAM_COMPARISON.md)):

- **Upstream 2.0.0** (wordpress.org, ~2,000+ active installs) — the direct continuation
  of the 1.6 line; its release content is raising the WordPress/PHP minimums plus WP 7.0
  support (~14 commits / ~730 lines since the fork point).
- **Fork 3.0.0** (this repo) — a parallel rewrite on the same slug: the legacy 1.x code
  is **preserved and still runs**, with a new `Smaily\Connect` (PSR-4) architecture, React
  admin UI, and the Campaign Intelligence integration layered alongside it. In-place
  upgrades keep behaving like 1.x until the merchant completes the new setup wizard.

## The case — why a wholesale takeover

The fork contains everything the wordpress.org plugin does **and** supersedes it:

| | Upstream 2.0.0 (wordpress.org) | Fork 3.0.0 |
|---|---|---|
| 1.x feature set (subscriber sync, abandoned cart, CF7, Elementor widget, checkout opt-in block, product RSS feed) | ✓ | ✓ (preserved; RSS URL builder rebuilt into the new UI) |
| Modern setup wizard + admin UI | — (PHP-partial pages) | ✓ React + TypeScript + Tailwind, mobile-first |
| Smaily Campaign Intelligence (catalog / customers / orders / browse ingest, backfill, attribution, identity-merge) | — | ✓ every domain verified against the deployed engine |
| Background work | WP-Cron | Action Scheduler |
| Idempotent ingest (per-record `event_id`, per-item error split) | — | ✓ durable queue + Event Log + retry + health notices |
| GDPR export / erase / opt-out | — | ✓ via the WP Privacy API |
| Credential encryption | **AES-256-CBC, static IV** (an `AUTH_KEY` prefix inside the stored blob — a DB dump leaks the key prefix; equal plaintexts → equal ciphertexts) | **AES-256-GCM**, random per-message nonce; legacy blobs auto-re-encrypted on upgrade |
| PHP tests | **none** (`composer test` runs only the block's JS tests) | **374 unit (898 assertions) + 114 real-environment integration** (WP 7.0 + WC 10.7 + MariaDB via wp-env) |
| Static analysis | none at the released tag | **PHPStan level 6**, clean |
| WordPress.org Plugin Check | — | **clean** except 2 cosmetic warnings (`slow_db_query_meta_key` on the new transactional-email order-meta gate, v3.9.0) — see [`audits/CODE_QUALITY_AUDIT.md`](audits/CODE_QUALITY_AUDIT.md) |
| Security audit | — | **0 Critical / 0 High** — see [`audits/SECURITY_AUDIT.md`](audits/SECURITY_AUDIT.md) |

The takeover therefore loses upstream nothing (every 1.x behaviour and option key is
carried over, with an in-place upgrade path) and gains the rewrite, the integration, the
tests, and the crypto fix.

## What "the merge" actually means

It is **not** a `git merge` — the histories are parallel and the codebases are different.
It is **upstream replacing its plugin source with the fork's** and publishing it as the
next major version. Mechanics:

- **Version continuity is already solved.** The fork is `3.0.0`, above upstream's `2.0.0`,
  so the takeover lands monotonically and every existing install upgrades forward cleanly.
  (This is exactly why the fork renumbered to the 2.1.0-beta line and then to 3.0 — see
  DECISIONS F3-35.)
- **Distribution flips** from GitHub Releases to the wordpress.org SVN flow. The
  `Update URI` guard that used to keep wordpress.org from overwriting the fork was
  already removed in v3.9.0 (2026-07-23) — the flip now happens automatically the
  moment sendsmaily publishes a wordpress.org release version ≥ what's installed, with
  no separate header change needed at merge time.
- **Existing merchants** get the rewrite as a normal plugin update; legacy behaviour
  continues until they complete the wizard, so nothing breaks on update.

## Prerequisites & prep checklist

| # | Item | Owner | Status |
|---|---|---|---|
| 1 | **This proposal** — the case for the takeover | fork | ✅ this document |
| 2 | **Remove the `Update URI` header** (the one intentional Plugin Check finding) | fork | ✅ done (2026-07-23, ships in v3.9.0) |
| 3 | **React admin UI i18n** — wire `@wordpress/i18n` (`__()`, text domain `smaily-connect`) + `wp_set_script_translations`; the UI is currently English-only (0/41 components localized). wordpress.org reviewers expect a translatable UI | fork | ✅ done (W-7) |
| 4 | **Inline `<script>` → enqueue** — move the admin-notice dismiss handler to an enqueued script | fork | ✅ done (W-5) |
| 5 | **Reconcile 3 open upstream items** — #120 (translation `.pot` catalogs, manual merge), #128 (WP7 / minimum-version bump), #132 (`release.sh` HTTP-429 recovery) | fork | ✅ fork-side dispositions below — Smaily confirms |
| 6 | **wordpress.org submission mechanics** — SVN (not git), readme.txt assets/screenshots, the plugin-review queue | Smaily | ⏳ Smaily-owned |
| 7 | **Pilot passes** — a real-merchant pilot against the acceptance criteria in [`TESTING.md`](TESTING.md) before the rewrite reaches ~2,000 production installs | joint | ⏳ prerequisite |

Items 2–4 are concrete plugin work, done. Item 5's dispositions are below — a quick
Smaily confirmation closes it, no further fork work is expected. Items 6–7 are
Smaily-owned / gating.

### Item 5 dispositions (2026-07-23)

> **Update (2026-08-04) — `sendsmaily/main` merged into the fork's `main`** to clear
> the conflicts blocking PR #135. The dispositions below stand, with one fact changed:
> **#132 is no longer pending a cherry-pick** — the `release.sh` HTTP-429 recovery came
> in with the merge, so the fork's copy is upstream's current one whichever release flow
> Smaily keeps. #120 and #128 resolved exactly as recommended: the fork's translation
> catalogs and its WP 6.6 / PHP 8.0 floors won the merge. The other 14 upstream commits
> (#118–#134) were legacy-plugin maintenance; their non-colliding parts merged in, the
> legacy admin UI this rewrite deletes stayed deleted. Details in STATUS.md.

- **#120 — translation `.pot` catalogs, manual merge.** Obsoleted by the fork's own
  i18n pipeline. The fork rebuilt translation tooling around `bin/build-i18n.sh`
  (esbuild-transpiles the TSX admin source so `wp i18n make-pot` can see the `__()`
  calls it otherwise can't parse, then regenerates the `.mo`/script-translation-JSON
  catalogs from the committed `.pot`/`.po`). The fork's `languages/smaily-connect.pot`
  + `-et.po` are already comprehensive (cover both the legacy admin surface and the
  new React UI, item 3) and are the intended canonical catalogs once the takeover
  happens — there is nothing left to merge from upstream's `.pot` addition.
  **Recommendation: close as obsolete**, no cherry-pick or manual merge needed.
- **#128 — WP7 support / minimum-version bump.** Checked against the fork's current
  floors (`readme.txt` / `smaily-connect.php`, 2026-07-23): `Requires at least: 6.6`,
  `Requires PHP: 8.0`, `Tested up to: 7.0`, `WC requires at least: 6.9`. These already
  **meet or exceed** #128's ask (PHP floor → 7.4, WP floor → 6.5) and the fork already
  declares WP 7.0 support, which was #128's headline goal — no code change is needed
  and there's no floor conflict to reconcile. One point worth a joint confirmation, not
  a blocker: the fork's PHP floor (8.0) is stricter than #128's ask (7.4) — a
  fork-side call made for the newer crypto/tooling stack — Smaily should sanity-check
  it against the wordpress.org install base's PHP distribution before submission.
  **Recommendation: close as satisfied by the fork's current floors**; Smaily confirms
  the PHP-8.0 floor is acceptable for the ~2,000 installs.
- **#132 — `release.sh` HTTP-429 recovery.** Only relevant if the wordpress.org
  release flow reuses upstream's `release.sh`. The fork carries the file unmodified
  since the fork point (last touched pre-fork, `e117c2c`) but doesn't use it — the
  fork's own release process is the local sequence documented in `CLAUDE.md` (build
  JS/blocks/i18n locally, `composer run package`, `gh release create`), which never
  invokes `release.sh`. **Recommendation:** if Smaily adopts the fork's release
  process at takeover, `release.sh` becomes dead code and #132 closes as obsolete
  alongside it; if Smaily instead keeps using the wordpress.org SVN `release.sh` flow,
  #132's patch applies cleanly to the fork's untouched copy and should be cherry-picked
  — a Smaily call, not a fork one.

## Risks & mitigations

- **Blast radius.** The rewrite would reach ~2,000 production installs. *Mitigation:*
  the in-place upgrade preserves legacy behaviour until the wizard is completed; the pilot
  (item 7) must pass first; the upgrade path and rollback are documented in
  [`MIGRATION.md`](MIGRATION.md).
- **Minimum-version bump (#128).** Resolved — the fork's current floors (PHP 8.0, WP
  6.6, tested to 7.0) already meet or exceed #128's ask; see the item 5 disposition
  above for the one open confirmation (the PHP-8.0 floor itself).
- **Translations (#120).** Resolved — the React UI is localized (item 3) and the
  fork's own i18n pipeline (`bin/build-i18n.sh`) makes its `.pot`/`.po` the canonical
  catalogs at takeover; see the item 5 disposition above.
- **Ongoing divergence.** Every week the fork advances, the eventual takeover is more
  work. *Mitigation:* decide direction soon; the fork freezes net-new scope once a
  go-ahead is given.

## The decision

The technical readiness is here (GA-released, audited, Plugin-Check-clean). What remains
is **a Smaily business decision**: Smaily owns the wordpress.org slug, the ~2,000 installs,
and the brand, and the rewrite couples the public plugin to Smaily Campaign Intelligence.

**Open questions for the Smaily team:**
1. Does Smaily want the public wordpress.org plugin to carry the Campaign Intelligence
   integration (vs. keeping it fork-/pilot-only)?
2. ~~If yes — which repository is the working one afterwards, who owns the wordpress.org
   SVN publish, and on what timeline relative to the pilot?~~ **Answered (2026-09-03,
   PRO-2281):** once PR #135 merges, the official repository's `main` is the working
   repository and the fork is archived read-only as history; releases are cut there
   under a plain version tag and Erkki runs the wordpress.org SVN publish via
   `./release.sh -u sendsmaily`. Timeline: the first official release (3.11.2) follows
   the merge, ahead of the remaining pilot acceptance batch.
3. Confirm the fork's PHP 8.0 floor (stricter than upstream #128's PHP 7.4 ask) is
   acceptable for the ~2,000 existing installs — see the item 5 disposition above.

On a "yes," the fork executes items 2–4 and supports items 5–7.

---

## References

- [`audits/UPSTREAM_COMPARISON.md`](audits/UPSTREAM_COMPARISON.md) — codebase comparison (point-in-time)
- [`audits/UPSTREAM_AUDIT.md`](audits/UPSTREAM_AUDIT.md) — commit-by-commit upstream divergence
- [`audits/SECURITY_AUDIT.md`](audits/SECURITY_AUDIT.md) · [`audits/CODE_QUALITY_AUDIT.md`](audits/CODE_QUALITY_AUDIT.md) — the pre-3.0 audits + Plugin Check pass
- [`DECISIONS.md`](DECISIONS.md) — architecture decisions (incl. F3-35, the version-collision guard)
- [`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md) — the plugin ↔ Campaign Intelligence contract
- [`TESTING.md`](TESTING.md) — pilot acceptance criteria · [`MIGRATION.md`](MIGRATION.md) — the 1.x → 3.x upgrade path
