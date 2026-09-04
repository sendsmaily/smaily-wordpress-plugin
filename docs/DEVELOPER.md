# Developer guide

How to set up, build, test, and contribute to the Smaily Connect plugin.
Read [`ARCHITECTURE.md`](ARCHITECTURE.md) first for the lay of the land;
`/CLAUDE.md` (repo root) carries the full operational knowledge this file
summarizes — when they disagree, CLAUDE.md is more detailed and current.

## Requirements

- PHP >= 8.0 (plugin floor; dev env runs 8.3), Composer
- Node (pin: keep local and CI in sync — npm lock resolution differs across versions)
- Docker (for wp-env integration tests)
- WP floor 6.6, WC floor 6.9 (the pilot store runs WC 6.9.4 / legacy order
  storage — see "Pilot-faithful env" below)

## Setup

```bash
composer install          # PHP deps + tooling (Action Scheduler, PHPCS, PHPStan, PHPUnit)
npm install               # React admin + beacon toolchain (Vite, vitest, eslint, tsc)
composer run install-block-modules   # blocks/ toolchain (needed before building blocks)
```

The integration environment is wp-env (`.wp-env.json`: WP 7.0, WC 10.7,
Polylang, PHP 8.3, the repo mapped to `wp-content/plugins/smaily-connect`).

### The `sg docker` gotcha

The agent sandbox strips the `docker` supplementary group, so a bare
`docker info` fails even though Docker runs and your user is in the group.
Prefix Docker-touching commands with `sg`:

```bash
sg docker -c "composer run test:integration"
```

Do NOT conclude "Docker unavailable" from a bare failure.

### wp-env start/stop

Use `npx @wordpress/env start --update` — NOT `npx wp-env`, which only prints
a deprecation notice and exits 0 without starting anything.

## Build / test commands

| Command | What |
|---|---|
| `npm run ci:strict` | The full local gate: PHPCS → PHPStan → PHPUnit unit → eslint → tsc → vitest. Must be exit 0 before any push. |
| `sg docker -c "composer run test:integration"` | Real WP + WC + MariaDB via wp-env (wrapper `bin/run-integration-tests.sh`). Look for the `OK (N tests)` line — the wrapper's exit-255 is a quirk. |
| `npm run build:admin` | Vite builds → `dist/admin/*`, `dist/public/js/sc-runtime.js`, `dist/public/js/sc-landing.js` (chained landing pass) |
| `composer run build` | Gutenberg blocks (`blocks/*/build/*`) |
| `bash bin/build-i18n.sh` | Rebuild `.mo` + script-translation `.json` from the committed `.po` (needs the wp-env container; the plain `compile-translations` script produces the WRONG admin-bundle JSON — see CLAUDE.md "React admin i18n") |
| `composer run package` | Distributable ZIP (see Release below — packaging alone is NOT a release) |

Gotchas that cost real time:

- **vitest-green ≠ typecheck-green.** vitest strips TS types without checking
  them; always run the full `ci:strict` chain, never just `npm run test`.
- **PHPCS: no cache locally** (`--no-cache` is baked into ci:strict), and never
  trim the summary line off PHPCS output.
- **CI's "Lint and test the codebase" workflow is pre-existing red on main**
  (it runs the integration suite without WooCommerce). The authoritative gates
  are LOCAL: `ci:strict` + the wp-env integration suite.

### Filtered / single integration tests

Run inside the `…-tests-cli-1` container with the INTEGRATION config — a
hand-rolled `docker exec` into the `…-wordpress-1` container loads the unit
bootstrap and fails misleadingly:

```bash
sg docker -c "docker exec wp-env-connect-<hash>-tests-cli-1 \
  php /var/www/html/wp-content/plugins/smaily-connect/vendor/bin/phpunit \
  --configuration /var/www/html/wp-content/plugins/smaily-connect/phpunit.integration.xml.dist \
  --filter <TestName>"
```

### The `smly_rec_*` snapshot guard

Integration runs overwrite the DEV site's rec-engine connection options with
fixture values. The wrapper (`bin/run-integration-tests.sh`, via
`bin/lib-smly-snapshot.sh`) automatically snapshots `smly_rec_*` before the
suite and restores + verifies `tenant_name` afterwards;
`bash bin/run-integration-tests.sh --restore-only` restores without running.
Walk scripts that write connection options must call `guardSmlyRec()` from
`bin/lib-smly-snapshot.cjs`. If a restored/printed `tenant_name` is `MiuMjau`,
STOP — that is the pilot's PRODUCTION tenant.

### Live-walks (real engine)

Scripts in `bin/walk-*.cjs` exercise a surface against the real engine. They
need a **one-time setup token from the "Smaily Connect test" SANDBOX tenant**
(ask Erkki; never MiuMjau, which is production). The exchange is secret-safe:
token via STDIN into a container-side `wp eval-file`, never on a command line,
never echoed; always verify the resulting `tenant_name` before sending
anything. Full mechanics: CLAUDE.md "Live-walk needs a fresh setup-token".
Mocks validate loosely — every formatted wire field needs live-walk coverage,
not just the mock (LESSONS §2.3/§2.4).

### Pilot-faithful env

The default wp-env (WP 7.0 / WC 10.7 / HPOS) does not match the pilot (WC
6.9.4, legacy order storage). To reproduce a pilot bug, drop in a
`.wp-env.override.json` pinning the old stack — recipe in CLAUDE.md
"Integration baseline is WP 7.0".

## Release process

Releases are cut in the official repository
[`sendsmaily/smaily-wordpress-plugin`](https://github.com/sendsmaily/smaily-wordpress-plugin)
— its `main` is the working repository, so your checkout's `origin` should
point there (`git remote -v`). The release ZIP is built by CI, not locally:
version bump in all pinned places → commit → push to `main` → `gh release
create <plain-version> --repo sendsmaily/smaily-wordpress-plugin --target main
--title … --notes-file …` (**plain** tag, e.g. `3.11.2`, no `v`, and no local
ZIP argument — `release.yml` builds, verifies with `bin/verify-release-zip.sh`
and attaches it) → `./release.sh -u sendsmaily` publishes that asset to the
wordpress.org SVN. The local build sequence (JS + blocks + i18n builds →
prod-vendor `composer install --no-dev` → `composer run package`) is how you
REPRODUCE or COMPARE a ZIP, not how the released one is produced. PCP (Plugin
Check) runs against a BUILT ZIP, never the dev tree. The full sequence, ZIP
checklist, and PCP gotchas live in CLAUDE.md "Cutting a release ZIP + GH
release" — follow it verbatim; the old fork flow (`v`-prefixed tag, `--repo
erkkimarkus/…`, hand-uploaded ZIP) is history, kept there only for reading
releases up to v3.11.1.
The re-audit policy (when a release forces a security/quality re-audit) is in
[`audits/INDEX.md`](audits/INDEX.md).

## Coding conventions

- **PHPCS**: WordPress-Core + WordPress.Security + PHPCompatibilityWP
  (`phpcs.xml.dist`); **PHPStan** level per `phpstan.neon.dist`
  (woocommerce-stubs are PHPStan-only, NOT runtime — unit tests build WC
  objects with PHPUnit mocks/shims).
- **Every PHP file** starts with `defined( 'ABSPATH' ) || exit;` (PCP requirement).
- **Classes**: production classes are non-`final` (testability — they get
  mocked); value objects are `final`.
- **Datetimes**: always through `Smaily\RecEngine\Support\IsoDate` (the engine's
  Zod requires the `Z` suffix; raw `format('c')` emits `+00:00` and is rejected).
- **Product keys**: always through `Support\SkuResolver` (`woo-<canonical_id>`,
  never the merchant SKU field — PRO-1224); parent grouping via
  `product_group_id()`.
- **Contact language**: only through `Support\ContactLanguageResolver`; omit
  the `language` key when it resolves empty (F3-47).
- **External URLs/paths centralized**: constants / `Constants::docs_url()` /
  the engine endpoints-map — no inline URL strings at call sites. Endpoints-map
  URLs carry the engine's `{email}`-style placeholders — substitute with
  `str_replace`, not `sprintf` (LESSONS §2.9).
- **No browser-visible tracker keywords**: the storefront script stays
  `sc-runtime.js` and the proxy `/relay` (ad-block lists, F3-41). Internal
  class names keep "beacon" on purpose.
- **Secrets**: tokens/API keys never appear in reports, commits, code, or
  command lines; use the STDIN-based file mechanics.
- **New wire surfaces**: a record that can't be keyed/sent is a terminal skip
  (observable in the Event Log), never a silent pre-enqueue drop (LESSONS §2.11).

## Testing conventions

- `tests/Unit` (Brain Monkey + PHPUnit; no WP) and `tests/Integration`
  (wp-env, real WP+WC; mock engine at
  `tests/Integration/Fixtures/RecEngineMockServer.php` + `mock-rec-engine/`).
  When the contract changes a wire shape, the mock moves in the SAME pass
  (CC-8/LESSONS §2.7 — a stale mock masks drift).
- The wp-env test env has HPOS enabled: order tests exercise `wc_orders`; the
  legacy `wp_posts` path is unit-tested only.
- Clean up test/walk orders with `wc_get_order( $id )->delete( true )` —
  `wp_delete_post()` is a silent no-op under HPOS (LESSONS §2.16).
- Vitest covers the React admin (`admin/src/**/*.test.tsx`) and the beacon
  (`public/js/beacon-core.test.ts`).

## How we work

Work proceeds in small sub-PRs with human checkpoints: plan first and wait for
go-ahead → code + tests → gates green (`ci:strict` + integration + relevant
regression) → report before the next sub-PR; commits go directly to `main`.
Do a **context audit** before building on a new area (`git log`, DECISIONS,
the contract section, the template code you mirror). Linear is the
coordination layer (project: *Smaily Connect for WooCommerce — v3 rewrite*);
one-way-door decisions get a Linear issue and wait for approval.

## Docs stay current — the same-commit rule

If your change makes a doc wrong, the change isn't finished until the doc is
fixed **in the same commit**: `STATUS.md` (state), `docs/DECISIONS.md`
(decisions), `CLAUDE.md` (operational facts learned the hard way),
`docs/LESSONS.md` (mistake classes), `docs/INDEX.md` (doc inventory), README
roadmap, and the bilingual merchant docs site (`docs/site/index.html` — BOTH
languages) when user-visible behavior changes. A stale doc you notice is
in-scope now, not a future task.
