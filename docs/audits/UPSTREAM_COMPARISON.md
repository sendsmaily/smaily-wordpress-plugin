# Upstream 2.0.0 vs fork 2.1.0-beta.1 — comparison

**Snapshot date:** 2026-06-12 (fork at `bed2aa6`, upstream tag `2.0.0` = `cb1564b`).
**Sources:** both codebases inspected directly (upstream tag fetched and read, not
recalled from memory); `docs/audits/UPSTREAM_AUDIT.md` for the commit-by-commit audit;
`docs/DECISIONS.md` F3-35 for the version-collision background.

## Context — what each "2.0" actually is

These two releases share a major version number and a wordpress.org slug
(`smaily-connect`) but are **unrelated codebases**:

- **Upstream 2.0.0** (sendsmaily, wordpress.org, 2026-06-03) is **not a
  rewrite**. Its own changelog says the release content is raising the minimum
  WordPress/PHP versions plus WordPress 7.0 support. It is the direct
  continuation of the 1.6 line: 14 commits and ~730 changed lines since the
  fork point (1.6.1).
- **Fork 2.1.0-beta.1** (this repo) is a parallel codebase living on the same
  slug: the legacy 1.x code is preserved and a new 2.x architecture (admin UI,
  recommendation-engine integration, ingest pipeline) runs alongside it.
  Originally numbered 2.0.0-beta.1; renumbered when upstream's 2.0.0 occupied
  the slot — see [Version collision](#version-collision-and-the-update-uri-guard).

## Version support and distribution

| | Upstream 2.0.0 (w.org) | Fork 2.1.0-beta.1 |
|---|---|---|
| PHP minimum | 7.4 | **8.0** |
| WP minimum / tested up to | 6.5 / 7.0 | 6.6 / 7.0 |
| WooCommerce support | tested up to 10.8.1 (no floor declared) | **explicit floor 6.9** (pilot merchant runs WC 6.9.4) … tested up to 10.7 |
| Distribution channel | wordpress.org, ~2000+ active installs | GitHub Releases (first pre-release `v2.1.0-beta.1-rc.1`); `Update URI` header blocks w.org updates |
| Release status | stable | beta / pilot candidate |

## Features

| Capability | Upstream 2.0.0 | Fork 2.1 |
|---|---|---|
| Subscriber sync, abandoned cart, CF7, Elementor widget, checkout opt-in block, product RSS feed | ✓ (1.6 feature set, unchanged) | ✓ (legacy carried over; RSS URL builder rebuilt in the new UI) |
| Setup wizard + modern admin UI | — (PHP-partial admin pages) | ✓ React + Tailwind, mobile-first |
| **Recommendation engine** — catalog/customers/orders/browse ingest, backfill, attribution flow | — | ✓ every ingest domain live-walked against the deployed engine |
| Background work | WP-Cron | Action Scheduler |
| Idempotent ingest (per-record `event_id`, D6 per-item error split) | — | ✓ |
| GDPR export/anonymize endpoints | — | ✓ |
| Migrations + 1.x → 2.x in-place upgrade path | n/a (it *is* the 1.x line) | ✓ (legacy behaviour continues until the wizard is completed) |

## Stack and code quality

| | Upstream 2.0.0 | Fork 2.1 |
|---|---|---|
| PHP architecture | legacy `Smaily_Connect\*` classes | legacy **+** new PSR-4 `Smaily\Connect\*` (PayloadBuilder → IngestQueue → Flusher → HookHandler pattern in every ingest domain) |
| Frontend | PHP partials; npm build only for the checkout block | React 18 + TypeScript + Vite + Tailwind |
| Static analysis | none at the 2.0.0 tag (PHPStan was added on upstream `main` *after* the release) | PHPStan **level 6**, clean |
| PHP tests | **none** (`composer test` runs only the block's JS tests) | 312 unit (777 assertions) + 101 integration (real WP 7.0 + WC 10.7 + MariaDB via wp-env) |
| JS tests | block tests only | 24 vitest files + `tsc --noEmit` + eslint `--max-warnings 0` |
| Live verification | — | live-walk scripts per ingest domain against the deployed engine |
| Credential encryption | **AES-256-CBC with a static IV** (an `AUTH_KEY` prefix, stored inside the persisted blob — a DB dump leaks the key prefix, and equal plaintexts produce equal ciphertexts) | AES-256-GCM with a random per-message nonce; legacy blobs are automatically re-encrypted on upgrade |

## Known problems — comparison

**Present in both, fixed in both:** the six 1.6.2 bug fixes (empty-cart sync,
gender defaulting to male, birthday 1970 fallback, profile fields silently
dropped, Elementor `failure_url`, checkout-block asset path). Upstream fixed
them in 1.6.2; the fork cherry-picked all six the same day they were audited
(`docs/audits/UPSTREAM_AUDIT.md` #121–#126).

**Still open upstream (2.0.0):**

- The static-IV CBC credential encryption described above ships unchanged.
- Zero PHP test coverage; no static analysis in the released tag.

**Known fork risks (open, tracked):**

- Beta status ahead of the pilot.
- The legacy (non-HPOS) order-storage backfill path is unit-tested only — the
  wp-env integration environment runs HPOS mode while the pilot runs legacy
  storage (see STATUS.md / CLAUDE.md).
- Browse-beacon browser timing (which page fires which event) needs manual
  pilot verification; the live-walk covers the server side only.
- The `Update URI` header is load-bearing until the upstream merge — removing
  it re-exposes the auto-update clobber.
- Pilot ZIPs built before `bed2aa6` lack the guard and are vulnerable on sites
  with plugin auto-updates enabled.
- Divergence keeps growing (89 fork commits vs 14 upstream commits since the
  fork point at audit time; more since) — the eventual merge-back is real work.

## Version collision and the Update URI guard

Upstream's 2.0.0 sits on the shared `smaily-connect` wordpress.org slug. With
the fork at `2.0.0-beta.1` (< 2.0.0), WordPress would have offered — or with
per-plugin auto-updates enabled, silently applied — upstream's 1.x-line package
over the fork mid-pilot. Two independent guards shipped in `bed2aa6`
(DECISIONS F3-35): the `Update URI` plugin header (WP 5.8+ core then skips
w.org updates for the plugin entirely) and the renumber to 2.1.0-beta.1 (the
eventual merge-back lands monotonically: upstream 2.0.0 → rewrite 2.1.0).

**Still to reconcile with upstream:** #120 (translation catalogs — manual `.pot`
merge, ours has diverged) and #132 (`release.sh` HTTP-429 recovery — only
relevant if the w.org SVN flow is ever used).

## Maintenance

This is a point-in-time snapshot, not a living register. If upstream ships a
new release or the fork's feature set changes materially, either refresh the
snapshot date and affected rows or treat the document as historical. The
commit-level source of truth for upstream divergence is `docs/audits/UPSTREAM_AUDIT.md`.
