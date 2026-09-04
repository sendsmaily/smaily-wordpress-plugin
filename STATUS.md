# Smaily Connect — Current Status

**Single source of "where we are now."**

> ## Keeping this current — NOT optional
> A handoff doc that goes stale is worse than none: it hands the next agent
> false confidence. This file MUST be updated as part of the same commit that
> changes reality — never "later."
>
> **Update this file in the SAME commit when you:**
> - finish a sub-PR (move it to done, add the commit hash)
> - sync the contract (record the engine commit + what changed)
> - hit, resolve, or newly defer a lock condition / blocker
> - reach a milestone or change the roadmap
>
> **The rule:** if a change makes this file wrong, the change isn't done until
> this file is fixed in the same commit. Stale status is a defect — treat it
> like a failing test. If you (an agent) notice this file disagrees with the
> repo, fixing it is in-scope right now, not a separate task. Also bump
> _Last updated_ below.
>
> This already bit us once: the README roadmap table said Customers/Orders were
> "Pending / Awaiting W4/W5" long after they shipped, because it was written
> once and never refreshed. Don't let this file become that.

If this file and your memory disagree, trust this file and fix it. The roadmap
table in README is a high-level view; this is the working register.

_Last updated: 2026-09-04 (**PRO-2288 — the translation template's slug is
pinned, not read off the checkout directory.** `wp i18n make-pot` derives the
plugin slug (and from it the `Report-Msgid-Bugs-To` address) from the directory
name it runs in. Inside the wp-env container that directory IS `smaily-connect`,
so the committed header was right — but on the HOST path (`WP_CLI_BIN=vendor/bin/
wp`, which is what `release.yml` takes since PRO-2277) this clone is called
`connect`, and the header regenerated as
`https://wordpress.org/support/plugin/connect`. `bin/build-i18n.sh` now passes
`--slug=smaily-connect` to `make-pot`; reproduced before the fix and both paths
verified after it — the two headers differ only in `POT-Creation-Date`, and both
extract the same **571** msgids. The template does not ship and no catalog was
affected, so this is header hygiene, not a merchant-visible bug. Committed with
it: the catalog refresh this makes honest — `Project-Id-Version` 3.11.1 → 3.11.2
and the `#:` line references that drifted with the PRO-2286/PRO-2287/PRO-2298
edits (`SettingsEndpoint`, `TestConnectionEndpoint`); **no msgid or msgstr moved**
(570 entries before and after, 0 added, 0 removed, 0 changed translations) — and
`package-lock.json`'s two `version` fields, still on `2.1.0-beta.2`, set to
3.11.2. Gates: `npm run ci:strict` **exit=0**. No PHP touched, so no integration
run. Known, out of scope: the container's and the host's wp-cli disagree on
whether the plugin-header references carry a line number (`smaily-connect.php`
vs `…:13`) and on `update-po`'s entry ORDER, so a host regeneration still
produces a large no-op reordering diff; the committed files stay container-built.
No version bump — ships in 3.11.2.)_

Prior: 2026-09-04 (**PRO-2292 — the setup-completed flag is read
through one accessor.** `smly_plus_setup_completed` gated six things and every
one of them read the option raw with its own spelling; `HookHandler` kept the key
in a *private* const, so nothing else could reuse it. That is the PRO-1742 bug
shape (a gate reading a key nothing ever wrote), so the key now has one
definition — `Settings\SetupState::OPTION_SETUP_COMPLETED` — and one reader,
`SetupState::completed()`. Callers: `HookHandler::gate_closed()`,
`CartHookHandler::tracking_enabled()`, `CartAbandonmentSweeper::enabled()`, four
`Bootstrap` sites (the NotificationManager Smaily probe, `on_contact_sync_tick()`
incl. the PRO-2287 gate, the LegacyHookBridge strip, the ProfilingConsent client
factory), `EnvDetector`'s `setupCompleted` hydration and `admin/wizard.php`'s
Settings redirect; the wizard's Finish route (`SettingsEndpoint::save_finish()`)
writes through the same constant. **No behaviour change** — all six sites already
ran the identical `(bool) get_option( …, false )`, so nothing had to be unified
and no existing test expectation moved; the once-per-request gate log stays in
`gate_closed()` where it was. New `tests/Unit/Settings/SetupStateTest.php` pins
the accessor's truthiness (unset/on-shapes/off-shapes) and the key string. Gates:
`npm run ci:strict` **exit=0** (PHPUnit unit **754**, vitest **288**, PHPCS 0
errors, PHPStan `[OK] No errors`) and `sg docker -c "composer run
test:integration"` **OK 256 tests / 1492 assertions, 1 pre-existing skip**, dev
sandbox tenant "Smaily Connect test" restored. **No version bump** — ships in
3.11.2, which is still untagged. DECISIONS PRO-2292; CLAUDE.md's PRO-1742 note
names the twin.)_

Prior: 2026-09-04 (**PRO-2298 — the wizard's Campaign Intelligence
step now carries marketing's final introduction (GMS-11).** The two sentences
the step opened with ("Sync product, customer and order data to Smaily Campaign
Intelligence…" + "Contact Smaily to activate Campaign Intelligence tool and more
automation workflows.") are replaced by Tanel's final copy, verbatim per Erkki's
2026-09-04 decision — one paragraph on what Campaign Intelligence does for the
merchant, one on what it costs (€250/month on top of the regular Smaily monthly
payment) and how to activate it. Estonian is Erkki's draft in this commit and
**still awaits his proofread before the release**; the product name stays
untranslated, matching the heading's existing ET msgstr. Wizard-only: the
`inSettings` branch of `Step4Recommendations` is untouched, so the Settings tab
gains no introduction. Catalogs: both msgids replaced in
`languages/smaily-connect.pot` + `…-et.po` (nothing else used the old strings)
and the gitignored `.mo`/`.json` rebuilt with `bin/build-i18n.sh` — the admin
catalog `smaily-connect-et-464ceaab21588225a35cae9f83dfa47d.json` carries both
ET strings. New `Step4Recommendations.test.tsx` pins all three acceptance
criteria (both paragraphs render in the wizard, the old sentence is gone, the
header block is absent under `inSettings`). `readme.txt` gains one merchant
line in the existing `= 3.11.2 =` block; **no version number changes** — this
ships in 3.11.2, which is still untagged. Gates: `npm run ci:strict` exit 0 (288
JS tests); no PHP changed, so no integration run. Browser-rendered ET remains
human acceptance — `playwright-core` is not installed in this checkout, so the
evidence is the built bundle (EN msgid present) plus the ET catalog under WP's
expected `md5('dist/admin/admin.js')` filename.)_

Prior: 2026-09-04 (**3.11.2 version bump — the tree is release-ready;
nothing is tagged.** Version `3.11.2` in the four places plus the three test
pins (`smaily-connect.php` header + both constants, `package.json`,
`readme.txt` Stable tag, `tests/Unit/ConstantsTest.php`, `tests/bootstrap.php`,
`tests/phpstan-bootstrap.php`) — the same file set the 3.11.1 bump touched.
`readme.txt` gains a merchant-language `= 3.11.2 =` changelog block (the two
attribution fixes, PRO-2286's stored-password connection test, PRO-2287's
wizard-gated daily catch-up, the CI-built and source-map-free package,
PRO-2291's rewritten migration guide) and a 296-character Upgrade Notice
written for a 2.0.0 store taking this through the wordpress.org updater.
Gates: `npm run ci:strict` exit 0; integration suite green; and the full local
ZIP pre-flight (admin + landing bundles, blocks, i18n, `--no-dev` vendor,
`composer run package`) verified with `bin/verify-release-zip.sh
smaily-connect.zip 3.11.2` before the ZIP was deleted — the released asset is
still CI's, this only proves the tree builds it.)_

Prior: 2026-09-04 (**PRO-2291 — the migration guide now describes the
upgrade merchants actually take.** `docs/MIGRATION.md` was still a 1.x → 2.0
document: it named `smaily-connect-2.0.0.zip` as the NEW package, told the
merchant to upload it by hand and to "verify Smaily Connect shows version
2.0.x". The ~2,000 stores on the directory's 2.0.0 will take
`2.0.0 → 3.11.2` through WordPress's own updater instead. Rewritten to that
path, structure and voice kept: the recommended route is `Dashboard → Updates`
/ auto-update (`wp plugin update smaily-connect` for CLI), with the manual-ZIP
+ `--force` route kept as the agency/staging alternative; every version,
package and "verify it shows X" mention is now 2.0.0 → 3.11.2. The steps were
read back against the PRO-2285 rehearsal record and now state what it observed:
which settings carry over (credentials silently re-encrypted and still
decrypting, subscriber-sync options incl. the optional fields, checkout opt-in,
abandoned-cart status + cutoff, RSS values), the three legacy WP-Cron events
cleared and the recurring Action Scheduler jobs armed, the legacy admin page
gone with the old Settings URL redirecting into the wizard, the legacy
abandoned-cart table kept with its un-sent rows drained once into
`smly_plus_cart_session`, and deactivate/reactivate being safe. The wizard step
list is the real six (Create a connection / Contacts / Automated letters /
Campaign Intelligence / Subscription forms and RSS / Overview). PRO-2286's
pre-filled-fields wording and PRO-2287's live-vs-daily split are kept, with
Step 3 now saying explicitly that the live sync is the ONLY sync until the
wizard is confirmed. Rollback restated as 2.0.0 from the directory's Advanced
View → Previous versions, with an honest note that the rollback direction was
not rehearsed and the re-encrypted password may need re-entering. Also
corrected in passing because they were false in the same document: the
WooCommerce floor (said 10.0, the plugin requires 6.9) and the wizard URL
(`admin.php?page=smaily-connect` — a 403 in the rehearsal; the page is
`smaily-connect-wizard`); the `smly_plus_cart_session` table was missing from
the verify + cleanup lists. `README.md` and `docs/INDEX.md` describe the guide
by its new subject. No plugin code, no `docs/site/index.html` change (its
upgrade note does not contradict the rewrite); the repo has no Markdown lint,
so no gates run.)_

Prior: 2026-09-04 (**PRO-2287 — an upgraded store makes no scheduled
call to Smaily before the merchant confirms setup.** The PRO-2285 rehearsal
found the daily `smly_plus_contact_sync` tick running its reconcile +
contact-refresh against the carried-over legacy credentials on a store whose
wizard was not finished — while the live checkout/registration path is gated on
`smly_plus_setup_completed` and the legacy daily mass-send is retired on upgrade
(F3-53/F3-48.3), leaving the daily tick as the one scheduled outbound caller on
an unconfirmed store. Decision A (Erkki, 2026-09-03):
`Bootstrap::on_contact_sync_tick()` now returns early — logging `[smaily-connect
contact.sync] skipped: setup not completed` — while that option is false, so
BOTH steps are skipped (no Smaily call, no contact-refresh scheduling) and the
daily catch-up resumes on the next tick after the wizard is confirmed. The gate
sits at the tick, NOT inside `ContactReconciler`/`BackfillJob` — those are also
driven by the wizard's own Backfill UI and REST route, where the merchant has
explicitly asked for the work. Live syncing is untouched (the legacy hooks own
it until Finish; `HookHandler::gate_closed()` and the `LegacyHookBridge`
integration test are unchanged). Proven on the dev wp-env by firing the real AS
hook: with the option false the debug log carries only the skip line and no
contacts tick is scheduled; with it true the same call reconciles (a real
outbound Smaily request) and starts the refresh (`nothing_to_sync`) — the option
was restored to its prior value. Docs: `docs/MIGRATION.md`'s "contact sync
continues uninterrupted" is now split into the two truths (live syncing
continues; the daily catch-up pauses and resumes after the wizard), and the
"Don't skip the wizard indefinitely" paragraph follows; `docs/site/index.html`'s
upgrade note says the same in BOTH languages. DECISIONS PRO-2287. Gates: `npm
run ci:strict` exit 0 (751 unit + 285 JS tests), integration 256 tests OK via
`sg docker`, dev connection restored to the sandbox tenant.)_

Prior: 2026-09-04 (**PRO-2286 — an upgrading store can verify its
Smaily connection without retyping the API password.** The wizard's Step 1
blocked Steps 2-6 until "Test connection" succeeded, and the test endpoint
rejected an empty password — so a store upgraded from the wordpress.org 2.0.0
package, whose credentials work but whose password never reaches the browser,
could only get past Step 1 by minting a new Smaily API user (the save path has
always treated an empty password as "keep the stored one"; only the test did
not). `TestConnectionEndpoint` now takes `password` as optional and, when it
arrives empty, tests with the password stored for the default account — but
ONLY when the submitted subdomain + username still equal that stored set, so
typing a different account can never be vouched for by the old one; everything
else answers exactly as before. `EnvDetector::saved_settings()` publishes one
new boolean, `smailyHasStoredPassword` (the value never leaves the server);
`CredentialBlock` enables the Test button with an empty password while that
flag holds and the account on screen is still the hydrated one, marks the
password field not-required and shows "Leave empty to keep using the stored
password." (EN + ET, catalogs rebuilt). The save path was NOT changed — a
Continue after a stored-password test goes through the existing
`persist_credentials()` empty-password preserve branch and flips
`smly_plus_default_connection_verified`, so Steps 2-6 unlock as they do today.
Proven live on the dev wp-env through the real REST route with a synthetic
credential set (only the Smaily network hop stubbed): empty password →
`connected=true` with the STORED password on the wire; the same call with
Smaily refusing → `connected=false` "Smaily did not accept those credentials.";
a different subdomain typed → the unchanged "Subdomain, username, and password
are required." Docs: `docs/MIGRATION.md` now describes the pre-filled fields
and the verify step truthfully and names the 2.0.0 → 3.11.2 upgrade merchants
will see; `docs/site/index.html` Step 1 gained the same note in BOTH languages.
Also fixed in passing because it blocked the gate: `bin/lib-smly-snapshot.sh`'s
container lookup relied on `docker ps` ORDER — docker ORs repeated `--filter
name=` values, so the helper could hand back `…-tests-mysql-1` ("php: not
found") instead of the dev cli container; it now keeps only `*-wordpress-1`
candidates. Gates: `npm run ci:strict` exit 0 (748 unit + 285 JS tests),
integration 256 tests OK via `sg docker`, dev connection restored to the
sandbox tenant.)_

Prior: 2026-09-03 (**PRO-2285 — the wordpress.org upgrade path was
rehearsed end to end: real 2.0.0 package → the v3 package built from `main`.**
A throwaway wp-env (WP 7.0 / PHP 8.3 / WC 11.1, its own ports, the repo NOT
mapped in) ran the directory's real `smaily-connect` 2.0.0, configured as a
legacy merchant would be (synthetic credentials, subscriber sync on with
optional fields, checkout opt-in on, abandoned cart on with a 45-min cutoff,
RSS values, a product + a customer + one un-sent legacy cart row, all three
legacy WP-Cron events armed), then took `wp plugin install <zip> --force` —
the wordpress.org updater's effective path — onto the 3.11.1 ZIP built with the
runbook sequence and passed by `bin/verify-release-zip.sh`. **All six acceptance
criteria passed**: no fatal (the only debug.log lines are the plugin's own audit
/ drain logs), `smly_plus_plugin_version=3.11.1`; every legacy option kept its
2.0.0 value and is read by the new code (`ContactSyncMode::sync_enabled()`
true, `effective_selection()` = the three optional fields, normalised cart
status, cutoff 45), and the credential blob was silently re-encrypted CBC →
`smy2:` GCM and still decrypts; all three legacy WP-Cron events cleared with
the audit logged and the twelve `smly_*` recurring AS actions armed; the legacy
`admin.php?page=smaily-connect` surface is gone (403) while the wizard renders
200 and Settings redirects to it; the legacy abandoned-cart table survived
untouched and the new tables came up at schema version 10, with the one-time
legacy-cart drain moving the un-sent row into `smly_plus_cart_session`;
deactivate → reactivate (and a WooCommerce reactivation — the F3-53 scar)
duplicated no AS row, re-ran no one-time migration and resurrected no legacy
cron. **Two merchant-visible findings, filed as their own issues, no plugin
code touched:** (1) the wizard's Step-1 "already connected" shortcut cannot fire
on an upgraded legacy store — `smly_plus_default_connection_verified` is only
ever written by a new-code save, so `smailyConnected` hydrates false and the
merchant must retype the Smaily API password (a hard gate: Steps 2–6 stay
locked until Test connection succeeds, and `TestConnectionEndpoint` rejects an
empty password), which `docs/MIGRATION.md` still describes as "pre-filled; just
verify and continue"; (2) before the wizard is finished the daily
`smly_plus_contact_sync` tick already runs the new reconcile + contact-refresh
against the carried-over legacy credentials (observed: an outbound Smaily API
call, then `nothing_to_sync` under the default consent audience), while the
legacy daily mass-send is retired by design (F3-48.3) — so the "sync continues
uninterrupted" promise in `docs/MIGRATION.md` needs re-stating. Repo change:
`TESTING.md`'s wp-env commands corrected to `npx @wordpress/env …` (the bare
`npx wp-env` alias was confirmed live to print a deprecation notice and exit
without starting — README and DEVELOPER.md already said so). The throwaway env
was destroyed; the repo's own wp-env containers were never started.) (handoff
refreshed 2026-09-03 after the PRO-2287 decision)_

Prior: 2026-09-03 (**PRO-2281 — the developer docs and the release
runbook now name the official repository.** Erkki's decision today: once PR #135
merges, `sendsmaily/smaily-wordpress-plugin`'s `main` IS the working repository
and the fork is archived read-only as history; releases are cut there under a
**plain** version tag (`3.11.2`, no `v` — PRO-2277), the release workflow builds,
verifies and attaches the ZIP, and `./release.sh -u sendsmaily` publishes it to
wordpress.org. Docs brought to that truth: `README.md` (clone address,
distribution sentence, and the "Relationship to upstream" section rewritten as
"Relationship to the 1.x line" — README ships in the ZIP, so it is
merchant-visible too), the CLAUDE.md release runbook (step 7 is now push → `gh
release create <plain-version> --repo sendsmaily/… ` with **no** local ZIP
argument → wait for CI → `release.sh`; steps 1–6 stay as the way to REPRODUCE or
COMPARE a ZIP; the fork flow is marked HISTORY, and so is the `upstream/main`
merge section — one repository, no fork split), and `docs/DEVELOPER.md`. The
runbook also states the expectation that the working checkout's `origin` is the
official repo (**the local remotes were NOT changed** — that is Erkki's
one-time action, as are the merge, the bump, the release and the GitHub archive).
`docs/UPSTREAM_MERGE_PROPOSAL.md`'s open question 2 (which repo is the working
one / who owns the SVN publish) is answered in place. DECISIONS PRO-2281.
Docs-only; no code, so no gates run.)_

**Next session opens with:**

- **(a) The 3.11.2 bump — DONE** (this file's own commit): versions, test pins,
  `readme.txt` changelog + Upgrade Notice; `ci:strict`, the integration suite and
  a full local ZIP pre-flight (`verify-release-zip.sh … 3.11.2`) all green.
  Nothing tagged, nothing released, remotes untouched.
- **(b) The ONE blocker for 3.11.2 is an approving review on PR #135 from a
  sendsmaily admin** — asked of Kait 2026-09-03 (PRO-2283 + a review request on
  the PR). A direct push to the official `main` was REJECTED by its branch rules
  (no merge commits — ours is the 2026-08-04 upstream merge `4a3d979` — and one
  approving review required); the repo allows **squash-merge only**, and Erkki's
  decision is squash + Kait's approval, with the full history staying in the
  archived fork. `origin` already points at the official repo, so until the merge
  lands workers push to the fork = PR #135's head (`git push
  https://github.com/erkkimarkus/smaily-wordpress-plugin.git main:main`).
- **(b2) After approval, in order** (Erkki's, one-way door): squash-merge PR #135
  → reset local `main` to `origin/main` → `gh release create 3.11.2 --repo
  sendsmaily/smaily-wordpress-plugin --target main --notes-file
  ~/.local/state/smaily-connect/release-notes-3.11.2.md` (plain tag, no `v`, no
  local ZIP argument) → CI attaches the verified ZIP → verify it →
  `./release.sh -u sendsmaily` (**install SVN first**) → update the pilot stores
  by hand → archive the fork read-only.
- **Done this session, all landing in 3.11.2:** PRO-2292 (the setup-completed
  flag reads through `SetupState::completed()`; refactor only), PRO-2298 (the
  wizard's Campaign Intelligence copy — the ET rendered in a browser is still
  human acceptance, see (d)) and PRO-2288 (the POT slug pin + catalog refresh).
  None of them moves the gate; it is (b).
- **PRO-2295 decided:** no rollback rehearsal — `docs/MIGRATION.md` keeps its
  honest hedge that the 3.11.2 → 2.0.0 direction was not rehearsed.
- Human acceptance on a real legacy store is still open for PRO-2286 (criterion
  1 was proven live only against a synthetic account with the Smaily hop
  stubbed). No AI-attribution trailers on commits from here on.
- **(c) Human-acceptance batch** once MiuMjau is updated: PRO-1679, PRO-1680 +
  PRO-1729, PRO-1681, PRO-1683, PRO-1684, plus an Estonian admin-strings
  spot-check (the ZIP's `.mo` is host-built — PRO-2277).
- **(d)** PRO-1770 needs Erkki's list of stores; PRO-1893 tenant-inactive
  handling needs a design nod first. **GMS-11 / PRO-2298 is DONE** (in 3.11.2) —
  the only thing left on it is Erkki's proofread of the Estonian paragraphs
  before the release.
- **Open question:** PRO-1878 awaits the engine team's answer (since 2026-08-10).
  **Low backlog still open:** PRO-2279 (Actions majors), PRO-2282 (developer
  README).

Prior: 2026-09-03 (**PRO-2280 — pre-merge tidy ahead of PR #135.**
Three things go stale the moment the upstream merge lands, fixed now. (1)
`readme.txt`'s changelog note pointed merchants at the FORK's releases page for
the 1.x/2.x history — that history lives upstream, and the Contribute section
already names `sendsmaily/smaily-wordpress-plugin`; the version-history line now
does too, so the shipped readme carries one canonical address. No other shipped
metadata points at the fork as the plugin's home (`Plugin URI` is the smaily.com
user manual; `composer.json`/`package.json` carry no repository/homepage URL) —
the remaining `erkkimarkus` mentions are `README.md`'s clone line and dev/history
docs (DEVELOPER.md, DECISIONS, this file), left alone deliberately. (2) PR #135's
title and body still described the branch at **v3.10.0**; a refreshed description
is drafted (current version 3.11.1 + the unreleased packaging work, what shipped
since v3.10.0 in merchant language, the agreed publish path, the 2026-09-02
maintainer-access fact) — **drafted only, the PR is untouched** until Erkki nods.
(3) This file gained the handoff block below. `docs/UPSTREAM_MERGE_PROPOSAL.md`
remains the reference document and is unchanged apart from one current-state
line. Docs-only; no code, no gates run (nothing `ci:strict` lints was touched).)_

Prior: 2026-09-03 (**PRO-2277 — the release workflow now builds the
shippable ZIP, and refuses a wrong one.** The ZIP is a six-step build; the old
`release.yml` ran three of them — no vite pass at all (so no `dist/admin/*` and
neither storefront bundle), a `compile-translations` calling a bare `wp` that is
not on PATH (wp-cli is `vendor/bin/wp`) and that would not produce the
admin-bundle JSON name WordPress requests anyway, and the **dev** vendor tree
(phpunit, phpstan, wp-cli shipped to merchants). That was survivable while every
release was hand-cut locally; it is not, now that PRO-1196's publish path on the
official `sendsmaily` repo is "create a release → CI attaches
`smaily-connect.zip` → `./release.sh -u sendsmaily` pushes that asset to the
wordpress.org SVN". The workflow now runs the documented local sequence end to
end (`npm ci` → `npm run build:admin` → blocks → `bin/build-i18n.sh` →
`--no-dev` composer → `composer run package`) and gates the result with the new
**`bin/verify-release-zip.sh`** (also runnable locally against any ZIP): required
outputs present, dev-only material absent, no `sourceMappingURL` trailer in the
three shipped bundles, archive root `smaily-connect/` (what `release.sh` copies
into the SVN trunk), version consistent across header/constant/Stable tag.
**A release tag that does not equal the plugin header version fails the run
before anything is uploaded**; the tag convention on the official repo is the
**PLAIN version** (`3.11.2`, no `v` — that is what `release.sh` builds its
download URL from), with a leading `v` tolerated. `workflow_dispatch` builds and
verifies the same ZIP but uploads it only as a workflow **artifact** — a dispatch
never writes to a release. `bin/build-i18n.sh` gained a `WP_CLI_BIN` escape hatch
so the wp-cli steps can run on the host (no Docker on the runner; the wp-env
container stays the local default), plus two newer-wp-cli fixes (the dropped
`--purge` flag; `--use-map` already naming the catalog after the mapped path, so
the rename is a no-op). **Evidence:** fork dry run
[33742934146](https://github.com/erkkimarkus/smaily-wordpress-plugin/actions/runs/33742934146)
green on the first try; its artifact and a locally built ZIP from the same commit
(`ac1a8c9`) have **identical 376-entry file lists** and the same build stamp, both
pass the verifier, and the only content differences are two embedded
`PO-Revision-Date` timestamps and `vendor/composer/InstalledVersions.php`/`LICENSE`
(the runner's composer version) — the `.mo`/`.json` translation payloads are
byte-identical in content (177 / 361 entries). The refusal path was demonstrated
locally (`verify-release-zip.sh smaily-connect.zip 3.11.2` → exit 1). NB the host
wp-cli's `make-mo` keeps JS-only strings **out** of the `.mo` (they live in the
JSON catalog the admin bundle loads), so the `.mo` is ~40 kB smaller than the
container-built one and the ZIP is ~866 kB — strings referenced from PHP are
unaffected (verified: every dual-referenced string is still in the `.mo`).
**No version bump — ships with the next release cut.** DECISIONS PRO-2277.)_

Prior: 2026-08-10 (**PRO-1949 — the release ZIP no longer ships source
maps.** Erkki's call today: strip them. A vite map embeds the whole TypeScript
tree via `sourcesContent`, so `dist/admin/admin.js.map` alone was ~966 kB of a
~1.14 MB ZIP and quietly undid the fresh PRO-1781 source exclusions — **this
supersedes that entry's "the readable source stays in the bundles' source
maps"**; the readable source is the public GitHub repo. Two-part fix, both at
packaging so nothing can be forgotten at build time: `.zipignore` excludes
`*.map`, and `composer run package` runs the new `package:strip-map-refs`
(a `sed` deleting the `//# sourceMappingURL=` trailer from every staged `*.js`)
so no shipped bundle points at a file that isn't there. `vite.config.ts` keeps
`sourcemap: true` — local builds are untouched and still debuggable. Folded in
from the same issue: the `build:client` npm script was a byte-for-byte
duplicate of `build:admin` (there is no `client` vite mode) and is **removed** —
`npm run build:admin` alone builds admin + both storefront bundles; and the dead
`dist/client` references are gone (`.zipignore` line, the `test -f
dist/client/rec-engine-client.js` check in `lint_and_test.yml`, the vite header
comment, the CLAUDE.md release checklist, DEVELOPER.md, the StorefrontBeaconTest
skip hint). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan
`[OK] No errors`, PHPUnit unit **743/743**, eslint/tsc clean, vitest
**282/282**) and `sg docker -c "composer run test:integration"` **OK (256 tests,
1492 assertions, 1 pre-existing skip)**, dev sandbox tenant "Smaily Connect test"
restored. ZIP built once and verified, then deleted (dev vendor restored):
**880 144 B / 376 files vs. the 1 145 287 B of the PRO-1781 build — 265 kB
smaller**; zero `*.map` entries, zero `sourceMappingURL` occurrences in the three
shipped bundles, all three still parse (`node --check`), required files present,
tests/docs/`*.ts`/dev-vendor absent. DECISIONS PRO-1949. **No version bump —
ships with the next release cut** (v3.11.1 is already published).)_

Prior: 2026-08-10 (**PRO-1781 + PRO-1902 — build-output hygiene and the
last hardcoded attribution cookie names.** **PRO-1781 (dist hygiene, two
pre-existing items):** (1) vite's `publicDir` default treated `public/` as a
static-asset folder and copied it verbatim into `dist/`, so the ZIP shipped the
raw TypeScript sources of the very bundles it builds (`*.test.ts` included), a
second copy of `smaily-public.class.php` and the partials/template PHP —
`publicDir: false` (the copy step serves nothing; no bundle references an asset
in `public/`) plus a `.zipignore` line for `public/js*`, the counterpart of the
`admin/src*` exclusion the public side never got. ZIP now carries **zero**
`*.ts`/`*.tsx`; the readable source a wp.org reviewer would look for stays in
the bundles' source maps. (2) The build stamp moved from `dist/build-hash.txt`
to the plugin ROOT `build-hash.txt` — inside the out-dir every vite build wiped
it and the next integration run failed `BuildHashTest` on a missing build
artifact rather than on the change under test (three sessions lost a cycle to
it, two of them today). `package:hash`, `admin/wizard.php`, the test, `.gitignore`
and the CLAUDE.md notes follow; **the post-build `package:hash` ritual is retired**
(proven: `npm run build` then `BuildHashTest` green with no regeneration).
**PRO-1902:** `HookHandler`'s order stamping was the only reader of the four
attribution cookies still hardcoding their names, while `StorefrontBeacon`,
`LandingCapture`, `IdentityHookHandler` and `BeaconEndpoint` all resolve them
from the tenant's engine config — on a tenant with overridden names the cookies
were written under one name and looked up under another, so **no** order carried
a rec id, context, visitor token or session id. `ORDER_META_KEYS` becomes
`ORDER_META_COOKIES` (meta key => config key + shipped default) resolved through
`RecEngineSettings::config()`; the order META keys are our own schema and are
unchanged. MiuMjau is on defaults — latent, no live impact. Tests: 1 new unit
case pinning that tenant-renamed cookies still land on the order (verified it
fails on the old code). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors,
PHPStan `[OK]`, PHPUnit unit **743/743**, eslint/tsc clean, vitest **282/282**)
and `sg docker -c "composer run test:integration"` **OK (256 tests, 1492
assertions, 1 pre-existing skip)**; release ZIP built once and verified (1 145 287
B, no `*.ts`, `build-hash.txt` at the root, required files present, dev artifacts
absent) — **not published**. **No version bump — ships with the next release
cut.**)_

Prior: 2026-08-10 (**PRO-1942 — the orders wire now shape-checks all
four attribution signals at send time, not just the rec id.**
`OrderPayloadBuilder` validated `smaily_rec_id` (PRO-1710) and forwarded
`smaily_visitor_token` / `smaily_rec_ctx` / `session_id` on a bare non-empty
check. PRO-1896 (published hours earlier in v3.11.1) bounds what can be STAMPED
onto an order from now on, but a value stamped before it can already be sitting
on orders that retry through the flusher for the queue's lifetime — so the send
side needs the same rule, exactly as PRO-1710 argued for the rec id. **Fix:** the
visitor-token / context regexes move out of `LandingCapture` into a shared
`Smaily\RecEngine\Support\AttributionShape` (the `RecId` pattern — one definition,
capture and send both call it), which `OrderPayloadBuilder` now applies to all
three; `session_id` keeps PRO-1896's deliberately generous bound (the context
charset, 64 chars) rather than the UUID its producers emit, since nothing has ever
enforced a shape on it. An off-shape value is OMITTED (never truncated — a trimmed
token is a plausible-looking wrong value on the §5 wire) with a shape-only
DebugLog line; the order ships normally and the meta stays on the order. Also
**PRO-1943** (test-harness only): `tests/Integration/Support/EnvScrub.php` flushed
the per-key object cache for an explicit list that omitted the autoload=false
`smly_plus_plugin_version` stamp, so after a scrub `get_option()` served the
pre-scrub value and re-arming `Bootstrap::maybe_run_upgrade()` mid-suite was
impossible (the row is gone, so `update_option()` writes nothing and leaves the
cache); `Activation::OPTION_PLUGIN_VERSION` is now on the flush list and
`BackwardCompatTest` drives the full `maybe_run_upgrade()` path (verified both
ways: the converted test FAILS on the old EnvScrub in a filtered class run, passes
with the flush). That work also **explained the 2026-08-10 transient
`AutomationMarkerPipelineTest` triple failure** — nothing to do with the aborted
run's DB state: `wp_delete_user()` lives in `wp-admin/includes/user.php`, which no
front-end request loads, and that class's tearDown reached it only because some
earlier test in the run happened to pull it in (directly, or via dbDelta's
`upgrade.php`). Integration suite order is FILESYSTEM order, so any edit that
reshuffles the directory can move the incidental loader after it and all three
cases die in tearDown — reproduced exactly while doing this work. Fixed with the
one-line `require_once` guard its five siblings already carry. Tests: 1 new unit
case (all three off-shape signals dropped, the order still ships) + the
BackwardCompat upgrade case above. Gates: `npm run ci:strict` **exit=0** (PHPCS 0
errors, PHPStan `[OK] No errors`, PHPUnit unit **742/742**, eslint/tsc clean,
vitest **282/282**) and `sg docker -c "composer run test:integration"` **OK (256
tests, 1492 assertions, 1 pre-existing skip)** — run twice, stable, dev sandbox
tenant "Smaily Connect test" restored. DECISIONS PRO-1942. **No version bump —
ships with the next release cut** (v3.11.1 is already published)._

Prior: 2026-08-10 (**v3.11.1 — PUBLISHED**:
https://github.com/erkkimarkus/smaily-wordpress-plugin/releases/tag/v3.11.1,
tag on the bump commit `e6413e5`, asset SHA256 `fc8ea222…aa5c08a9` verified at
publish. PATCH
cut, fixes only: the three "No version bump — ships with the next release cut"
entries below (PRO-1878 capture side, PRO-1896 attribution hardening, PRO-1897
retired-option cleanup) plus doc hygiene — 9 commits since the v3.11.0 tag,
`ed49abc..HEAD`. **Also in this cut, PRO-1900 (wordpress.org readme prep):** the
two cosmetic readme warnings the v3.11.0 PCP gate left standing are fixed —
`== Changelog ==` now carries the 3.x line only (1.x/2.x history linked to the
GitHub releases page; **the real parser limit is 5 000 WORDS, not characters** —
the 3.11.0 gate row's "32 927 chars vs 5 000" was a chars/words mix-up, the
section was ~5 077 words, barely over; it is now ~3 300 words with ~35 %
headroom) and the 3.11.0 upgrade notice is re-worded to 298 bytes (PCP's
`strlen` limit is 300). Version bumped in all four places + the three test pins,
committed first: `e6413e52254e0f7964960c34e599396c81d410b8`. Builds:
`npm run build:admin && npm run build:client` (`dist/admin/admin.js`,
`dist/public/js/sc-runtime.js` 6.97 kB **and** `dist/public/js/sc-landing.js`
1.29 kB — separate vite passes, neither with a top-level `import`), then
`composer run package:hash` to restore the build-hash artifact vite had wiped;
all three `blocks/*/build/*` rebuilt. **i18n rebuild SKIPPED, justified:**
`git diff ed49abc..HEAD` is empty for `languages/` and `admin/src/`, and the
`includes/` delta adds/changes no translatable string, so the `.mo` + the
admin-bundle et JSON on disk (2026-08-07, from the 3.11.0 restamp) are current.
`composer install --no-dev --optimize-autoloader` → `composer run package` →
`composer install` (dev vendor restored). **ZIP verified**: 3.11.1 in the plugin
header, both constants and the readme Stable tag; clean non-dirty build-hash
`e6413e5`; required present (`dist/admin/admin.js`, both storefront bundles,
all three `blocks/*/build/*`, `vendor/autoload.php`,
`languages/smaily-connect-et.mo` + the admin-bundle et JSON, `composer.json`);
tests/docs/node_modules/`admin/src`/`dist/client`/dev-vendor absent (the only
shipped vendor package is `woocommerce/action-scheduler`); 400 files,
**1 191 727 B** (vs. v3.11.0's 1 191 528 B). **PCP against the built ZIP**
(`docker cp` into the wp-env `…-cli-1` container, unzipped to
`smaily-connect-pkg` — never the bind-mounted `smaily-connect` dir —
`--slug=smaily-connect --exclude-directories=vendor`, temp copies removed
after): **0 ERRORS, 3 WARNINGS** — down from 3.11.0's 5, with **both readme
warnings gone**; what remains is exactly the known accepted set (the
`BackfillJob.php` direct-DB-query pair + the `DynamicHooknameFound`
false-positive on `self::FILTER_WELCOME_ELIGIBLE`). Gates: `npm run ci:strict`
**exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit unit **741/741**,
eslint/tsc clean, vitest **282/282** across 37 files) and `sg docker -c
"composer run test:integration"` **OK (256 tests, 1491 assertions)**, 1
pre-existing environment skip (`BackwardCompatTest`), dev sandbox tenant "Smaily
Connect test" correctly restored post-run (not MiuMjau, `connected=1`).
**No new full audit for this cut, deliberately and per policy:** the delta is
9 commits / ~250 changed plugin lines, far under the ~2 000-line rule of thumb,
and its only security-sensitive content **is** the fast-follow the 2026-08-07
v3.11.0 delta security audit itself prescribed (its one Medium → PRO-1896, its
one Low → PRO-1897), already re-verified by that audit's own reasoning; no REST
route, `/relay` field set, capability, crypto, SQL, secret/PII or consent
surface changed. **Publication (tag + GH release) is the orchestrator's step —
not performed by this pass.** Register row in `docs/audits/INDEX.md`._

Prior: 2026-08-10 (**PRO-1896 — the browser attribution writer now
refuses what its server twin refuses, and an oversized cookie can no longer ride
an order.** The one Medium of the 2026-08-07 v3.11.0 gate delta security audit
(§1), agreed as a fast-follow. `public/js/lib/attribution.ts` had ported only
the rec-id UUID rule (PRO-1710) and accepted `smaily_vt` / `smaily_ctx` at any
value and any length, while its PHP twin `LandingCapture::resolve()` shape-checks
all three — and PRO-1767 newly hands that permissive writer to browse-OFF stores,
whose only writer used to be the strict PHP one. **Fix, both ends:** (a) the
writer gets `VISITOR_TOKEN_PATTERN` / `CONTEXT_PATTERN` mirroring the PHP regexes
verbatim (`/^vt_[A-Za-z0-9]{1,64}$/`, `/^[A-Za-z0-9._-]{1,64}$/`), applied
through the same per-slot `isValid` hook the rec-id already used — junk is never
cookied, though the param is still stripped from the URL; (b)
`HookHandler::save_attribution_cookies_to_order()` caps each cookie at the
longest value its shape can hold (`ORDER_META_MAX_LENGTH` — rec_id 36, visitor
67, context 64, session 64) and DROPS anything longer, because the capture fix
cannot reach a cookie already in a browser (it outlives the fixed bundle by its
30/365-day TTL) and a trimmed token would be a plausible-looking wrong value on
the §5 wire. Both storefront bundles rebuilt in their separate vite passes
(`sc-runtime.js` 6.97 kB, `sc-landing.js` 1.29 kB — still no top-level import,
so both still load as classic scripts). Tests: 2 new vitest cases (off-shape and
oversized vt/ctx refused) + the pre-existing fixtures that used off-shape tokens
(`vt1`/`vt9`/`vt-order`) corrected to real `vt_…` shapes; 2 new unit cases (over
the cap dropped, exactly at the cap still stamped) + 1 integration case (a
planted 200-char `smaily_rec_ctx` cookie is not stamped and the order ingests
without the field). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors,
PHPStan `[OK] No errors`, PHPUnit unit **741/741**, eslint/tsc clean, vitest
**282/282**) and `composer run test:integration` **exit=0, 255 tests / 1490
assertions / 1 pre-existing skip**. DECISIONS
PRO-1896. No version bump — ships with the next release cut._

Prior: 2026-08-07 (**PRO-1878 (capture side) — `checkout_complete`
no longer waits for the 30s batch window.** Browse events buffer for 30s or
until `pagehide` (`sendBeacon`), which is fine for every page a shopper stays
on — but `checkout_complete` fires on the order-received page, closed within
seconds, so the event depended almost entirely on the best-effort unload path.
The engine measured only ~52% of orders producing a `checkout_complete`, and
that asymmetry against `checkout_start` (unaffected — shoppers linger on
checkout past the timer) is the one genuine plugin-side loss in the PRO-1878
investigation. **Fix:** `RecEngineClient.track()` calls the existing `flush()`
immediately when the event is `checkout_complete` (`IMMEDIATE_FLUSH_EVENT`);
every other type keeps the window untouched. Reusing `flush()` — rather than a
second transport — keeps the **consent gate identical** (no consent still drops
the buffer and sends nothing) and rules out a double-send: `flush()` clears the
pending timer and takes the buffer synchronously before its first `await`, so a
`pagehide` landing mid-flight finds it empty, and the in-flight request is
`keepalive` so unload doesn't kill it. **JS-only — no PHP touched**, so no
integration run; `/relay` and the engine wire shape are unchanged. Tests: five
new vitest cases pin the immediate POST, that it carries the whole buffer, that
nothing re-sends on pagehide or the timer, that `checkout_start` still waits the
full 30s, and that a consent-less `checkout_complete` still sends nothing.
Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No
errors`, PHPUnit unit **739/739**, eslint/tsc clean, vitest **280/280**).
DECISIONS PRO-1878. **PRO-1878 stays OPEN** — this is the capture-side half; the
remaining question (how the engine counts/joins these events) is engine-side.
No version bump — ships with the next release cut._

Prior: 2026-08-07 (**v3.11.0 — PUBLISHED.** MINOR bump shipping the
sendsmaily upstream merge plus the whole PRO-16xx/17xx defect sweep — 100
commits since the v3.10.0 tag. User-facing headline (see the `readme.txt`
3.11.0 entry): the ticked contact fields (Phone/Gender included) now actually
reach Smaily and are actually asked for at checkout; contact import no longer
stops after the first hundred; turning "Sync contacts to Smaily" off really
stops it; the first-order automation also fires on the block/Store-API
checkout; contacts carry a per-automation "last run" field to segment on;
fully-returned order lines are reported to Campaign Intelligence as returns;
and the "force opt-in" choice on automation triggers is retired (an automation
never overrides an unsubscribe made in Smaily). **This is the "next release
cut" that every `No version bump — ships with the next release cut` entry
below was waiting for** — everything from the v3.10.0 tag to `5b3dbbc` is now
shipped. Version bumped in all four places + the three test pins, committed
first (`c73b7b9007554e484a4935ffdc93384d249f9e21`), then the i18n restamp
(`ed49abc79767854487cfb04a14df412d99c02942`) — both pushed to `origin/main`.
Builds: `npm run build:admin && npm run build:client` (`dist/admin/admin.js`,
`dist/public/js/sc-runtime.js` **and** `dist/public/js/sc-landing.js` — the
PRO-1767 second storefront bundle, built by its own vite pass); blocks rebuilt
(all three); **i18n rebuild RUN** (`bin/build-i18n.sh` — `.mo` + the
admin-bundle et JSON). `composer install --no-dev --optimize-autoloader` →
`composer run package` → `composer install` (dev vendor restored, verified).
**ZIP verified**: 3.11.0 in the plugin header, both version constants and the
readme Stable tag; required present (`dist/admin/admin.js`,
`dist/public/js/sc-runtime.js`, `dist/public/js/sc-landing.js`,
`blocks/*/build/*` all three, `vendor/autoload.php`,
`languages/smaily-connect-et.mo` + the admin-bundle et JSON, `composer.json`,
clean non-dirty build-hash `ed49abc`); tests/docs/node_modules/admin-src/
`dist/client`/dev-vendor absent (grep-verified, the only shipped vendor package
is `woocommerce/action-scheduler`); 401 files, **1 191 528 B** (vs. v3.10.0's
1 148 878 B — consistent with the 100-commit feature delta + refreshed i18n).
**PCP against the built ZIP** (`docker cp` into the wp-env `…-cli-1` container,
unzipped to `smaily-connect-pkg` — never the bind-mounted `smaily-connect` dir
— `--slug=smaily-connect --exclude-directories=vendor`, temp copies removed
after): **0 ERRORS, 5 warnings — the first fully error-free PCP release gate,
and the new baseline future gates compare against.** The
`plugin_updater_detected` ERROR every gate from 3.2.0 onward carried is
confirmed GONE (the `Update URI` header was removed in v3.9.0 ahead of the
upstream merge, F3-35). Four warnings are the known accepted set (direct-DB
query + no-caching on `BackfillJob`'s custom table; `DynamicHooknameFound` on a
prefixed `self::FILTER_*` constant — the register's documented false-positive
class). The fifth is real but **deliberately shipped as-is**:
`upgrade_notice_limit` — the 3.11.0 upgrade notice is 636 chars against PCP's
300 (earlier gates tightened the wording at the cut; Erkki's call this time is
to leave it and fold it into the **wordpress.org readme-prep** item together
with the changelog trim — the Changelog section is 32 927 chars against the
wordpress.org parser's 5 000, so everything below ~3.10.0 would be silently
truncated on a wp.org listing). Neither matters for a GH-fork release; both
matter for the upstream/wordpress.org submission (PR #135). **`ci:strict`
exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit unit **739/739**,
vitest **275/275** across 37 files, tsc/eslint clean). **Integration suite
RE-RUN in full**: `sg docker -c "composer run test:integration"` **OK (254
tests, 1485 assertions)**, 1 pre-existing environment skip
(`BackwardCompatTest` — the legacy double-sync premise doesn't apply in this
env), dev sandbox tenant "Smaily Connect test" correctly restored post-run
(not MiuMjau, `connected=1`). Gated by the delta security audit of the same
day (`SECURITY_DELTA_AUDIT_2026-08-07.md`, 0 Blocking/Critical/High; its 1
Medium is the `attribution.ts` validation asymmetry, explicitly a fast-follow).
**PUBLISHED 2026-08-07**: tag `v3.11.0` on
`ed49abc79767854487cfb04a14df412d99c02942` (HEAD, **not** the bump commit — the
i18n restamp landed after it, so tagging HEAD keeps the tagged tree consistent
with the ZIP's embedded build-hash), GH release
https://github.com/erkkimarkus/smaily-wordpress-plugin/releases/tag/v3.11.0,
ZIP SHA256
`a8c1c6800fb0f1dcf08394e09b54b73df87a9e73933540887f2912b136ba449d`._

Prior: 2026-08-07 (**v3.11.0 release gate — delta security audit
`v3.10.0..HEAD` done; 0 blocking findings, the release may proceed.** The
re-audit policy fires on both grounds here (release boundary + a delta far
over the 2 000-line threshold: **100 commits, 157 files, +12 080 / −2 166**,
the sendsmaily upstream merge plus the whole PRO-16xx/17xx defect sweep), and
on nearly every named high-risk surface — the public `/relay` route, a new
storefront JS bundle that writes cookies pre-consent, custom-table SQL,
REST admin routes, consent/GDPR posture. Full file-by-file read of the
production delta: **0 Blocking / 0 Critical / 0 High, 1 Medium (should-fix,
non-blocking), 1 Low, 4 Info**. The **Medium** is a validation asymmetry the
delta *widens* rather than creates: `public/js/lib/attribution.ts` ports the
PRO-1710 UUID check for `smaily_rec` but not the `vt_…` / slug shape+length
checks its PHP twin `LandingCapture::resolve()` applies to `smaily_vt` /
`smaily_ctx`, and PRO-1767's `sc-landing.js` newly hands that permissive
writer to browse-OFF stores whose only writer used to be the strict PHP one —
a crafted landing URL can plant an arbitrary/oversized value in a 30/365-day
cookie that rides to order meta and onto the §5 orders wire. No privilege
boundary, no data exposure, and the same path has been live on every
browse-enabled store since 3.4 → **fast-follow, not a blocker**. The **Low**
is the retired `smly_plus_contact_sync_automation_force_opt_in` option left
on disk unread. Everything else came back clean, and the delta's net effect
is **positive**: PRO-1712 removes surface from the only public route (verified
as a pure whitelist narrowing with no case/alias/nesting bypass), PRO-1710 /
PRO-1716 / PRO-1682 / PRO-1742 all tighten validation or consent, RetryPolicy
clamps a Smaily-supplied `Retry-After` and ends the infinite 60 s re-POST of
permanent 4xx refusals, and the upstream merge corrected three legacy SQL
statements that mis-`prepare()`d a table name. `sc-landing.js` was inspected
as built: 1 194 bytes, **zero** `fetch`/`XHR`/`sendBeacon`/top-level `import`,
boot blob carries no `beaconUrl`/tenant/key. Report
`docs/audits/SECURITY_DELTA_AUDIT_2026-08-07.md` + register row in
`docs/audits/INDEX.md`. Gate for this pass: `npm run ci:strict` **exit=0**
(PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit unit **739/739**,
eslint/tsc clean, vitest **275/275**); no product code changed. PCP against
the built ZIP belongs to the release-build pass. **Also truth-fixed:**
`docs/audits/MOCK_DIVERGENCE_AUDIT.md` §3 still said the PRO-1633
return-signals live-walk had "not yet run to completion" — it ran 2026-08-05
(**LIVE OK, 12/12**, already recorded above); §3 now says so. Re-probed today:
the dev wp-env's sandbox key is **401 again**, so the NEXT live-walk needs a
fresh "Smaily Connect test" setup token through
`bin/exchange-setup-token.php`; the 2026-08-05 result stands for this tree
(nothing on the `OrderPayloadBuilder` return path has changed since)._

Prior: 2026-08-07 (**PRO-1712 — a spoofed recommendation id can no
longer ride a browse event to the engine.** Contract v1.7.0 deprecated
`smaily_rec_id` / `smaily_ctx` on §6 browse to accept-and-ignore: the engine
dropped both columns and deleted the 4th-priority attribution fallback they
fed (it had never matched a purchase in production). Both are client-supplied
— read from the campaign cookies — so `BeaconEndpoint::EVENT_FIELDS`
whitelisting them left exactly the spoofing surface PRO-1486 closed for
`customer_email`, now for zero benefit. **Fix:** both keys leave
`EVENT_FIELDS`, so `validate_batch()` drops a client-supplied value before the
batch is forwarded. **Normal browse traffic is byte-identical on the wire** —
our JS client never sent them (`rec-engine-client.ts` `enrich()`, F3-49) — and
**real rec attribution is untouched**: it rides the ORDER path (`smaily_rec_id`
on §5, from the cookies `LandingCapture` writes), never the browse event. Tests:
unit `BeaconEndpointTest::test_deprecated_attribution_hints_are_stripped` pins
the strip; integration
`RecEngineBrowseProxyTest::test_deprecated_attribution_hints_never_reach_the_engine`
proves over the real `/relay` path that neither field reaches the mock engine
while the event still forwards with its legitimate `smaily_visitor_token`.
Mock unchanged (it never inspected either field) and no live-walk — this is
server-side FILTERING, nothing new is emitted. DECISIONS PRO-1712 (+ the
PRO-1486 follow-up marked closed, and the mock-divergence register's browse
caveat retired). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan
`[OK] No errors`, PHPUnit unit **739/739**, eslint/tsc clean, vitest
**275/275**); `sg docker -c "composer run test:integration"` **254 tests, 1485
assertions**, no failures, 1 pre-existing skip, dev sandbox tenant "Smaily
Connect test" restored post-run.
No version bump — ships with the next release cut._

Prior: 2026-08-07 (**PRO-1779 / PRO-1804 / PRO-1843 — contract
re-synced byte-identical to engine `bfebf942` (md5 `39afc210…`) — v1.8.0 →
v1.8.1, CC-8 pass.** Three engine doc commits since our `547ad4d6` sync:
`4104659` (§2 `403 tenant_inactive` is now reachable), `8c2f043` (§5 — a
partly-refunded line still counts as kept), `bfebf94` (v1.8.1 — the same
`tenant_inactive` response now also covers purged/offboarded tenants).
**What changed, and the follow-through call on each:**
  1. **§2 `403 tenant_inactive` is real, applies to EVERY API-key endpoint
     (§2–§14), and is now emitted for a suspended OR a GDPR-purged tenant.**
     The body is byte-identical in both cases, `"tenant_status": "suspended"`
     literal included — a fixed string, **never** a state discriminator, so a
     sender must not branch on it. Sender rule: non-retryable like `401` —
     stop sending, surface an admin notice. **Today we half-comply**:
     `AbstractD6Flusher::is_terminal()` treats any non-429 4xx as terminal, so
     a 403 marks those rows failed with no retry, and the health-check ping
     failure raises the generic `engine_down` notice after its grace window.
     What is missing is the *specific* signal — nothing stops the next batch
     from being attempted, and the merchant is told "engine unreachable"
     rather than "this account is deactivated". A dedicated notice is a
     user-visible surface needing a design call → **follow-up, not built here**.
  2. **§5 — a partially-refunded quantity stays KEPT** (Erkki decision
     2026-08-05, PRO-1597): `returned_at` marks the whole LINE, there is no
     per-unit field, so 1 of 3 refunded is not a return. **Already exactly what
     we send** — `OrderPayloadBuilder::items()` flags a line only when the
     accumulated refunded quantity reaches the full line quantity
     (`returns_by_item()`'s docblock says so in the same words). No change.
  3. **§5/§6 errata — `smaily_rec_id` is a UUID, not a free string**, and a
     malformed value rejects the WHOLE order (not just its attribution).
     Already implemented (PRO-1710): `LandingCapture` cookies only a
     well-formed UUID and `OrderPayloadBuilder` omits the field otherwise;
     the mock mirrors the live Zod check. No change.
  4. **v1.7.0 §6 deprecation follow-through — PRO-1712 done in the next
     commit** (the previous sync flagged it): `smaily_rec_id` / `smaily_ctx`
     leave `BeaconEndpoint::EVENT_FIELDS`.
  5. **§3 catalog `currency` stays OPTIONAL** (engine default `EUR`) and we
     still don't send it — unchanged from the previous sync's call; still a
     follow-up for a non-EUR store, not a sync obligation.
**Mock follow-through: none required.** No wire shape changed — v1.8.1 is a
PATCH documenting which engine states emit an already-specified response, and
the two errata document validation that has been live (and mock-mirrored)
since PRO-1710. No live-walk required for the same reason. Gate: `bash
bin/check-contract-staleness.sh` **green** (`OK: … byte-identical … engine
commit bfebf942…`)._

Prior: 2026-08-05 (**PRO-1772 — a registered customer's contact update
no longer reaches Smaily empty on a wizard-configured store.** The fourth and
last known reader of the merchant's field selection that still iterated it as a
MAP: `Data_Handler::get_user_data()`, the builder behind
`Subscriber_Synchronization::update_subscriber()`. On a wizard-configured store
(the selection is a LIST of names there) every `switch` case missed, so the
contact POSTed to Smaily carried **no email address and no fields at all** — an
API error in the log and nothing updated. Reachable from the WordPress profile
save, the WooCommerce account-details save, customer-created, and the
registered-user branch of the checkout opt-in — the same pre-wizard-Finish
window as the readers PRO-1743 fixed (LegacyHookBridge strips those hooks at
Finish), plus the retired legacy cron's sync helper. **Fix:** the same one-line
bridge as PRO-1743 — read `SubscriberPayloadBuilder::
effective_selection_legacy_keys()`, the shared interpreter's answer in the
legacy key names this reader speaks — so there is no fifth translation table to
drift. `store_url`/`user_email`/`language` are always in that answer (PRO-1743
semantics, unchanged). **A store configured before the wizard is unchanged.**
Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No
errors`, PHPUnit unit **738/738**, eslint/tsc clean, vitest **275/275**);
integration full suite **OK (253 tests, 1483 assertions)**, no failures, incl.
`SubscriberFieldFormsTest` (**OK, 8 tests**) — two new cases drive the REAL
registered-customer opt-in on the running store with only the Smaily transport
faked, against both stored shapes, and the wizard-shaped one **fails against
the pre-fix code** (`email` is null, the payload is empty). Merchant docs
`docs/site/index.html` **unchanged** — it already describes ticking additional
fields, which is what now happens. DECISIONS PRO-1743 (PRO-1772 addendum). The
shape-drift class is now closed: every known reader of the selection goes
through the one interpreter. No version bump — ships with the next release cut._

Prior: 2026-08-05 (**PRO-1767 — a connected store now captures campaign
attribution in the browser even with browse tracking OFF.** The browser-side
writer exists because a full-page cache serves a campaign landing without ever
running PHP, so the server-side `LandingCapture` is blind on exactly the hit
that matters. But that writer lived inside the full browse runtime
(`sc-runtime.js`), which is only enqueued when browse tracking is on — so a
connected store with the toggle off (the default) plus a page cache had **no
attribution writer at all**, and every campaign click landing there was lost.
Both sibling plugins (Shopify, Magento) write these cookies client-side
unconditionally for this reason; this is the reverse-parity fix from the
2026-08-04 cross-plugin assessment. **Fix:** a second, deliberately tiny
storefront bundle — `public/js/landing.ts` → `dist/public/js/sc-landing.js`
(~1.2 kB) — enqueued by `StorefrontBeacon` whenever the engine is connected and
the full runtime is NOT. It reads the campaign params, writes the three
first-party cookies, strips the params, and does nothing else: no transport, no
consent surface, no session cookie, not even the `/relay` URL in its boot blob
(`window.smailyConnectLanding` = cookie names + param names + TTLs). The two
bundles are **mutually exclusive** at enqueue time, so the cookies are never
written twice, and browse telemetry keeps its unchanged gate (browse toggle +
marketing consent, F3-50). The capture itself moved into
`public/js/lib/attribution.ts`, shared by both bundles — one implementation,
so the PRO-1710 UUID check can't drift between them. The new script's gate is
`LandingCapture`'s, not the beacon's: connected + the
`smaily_connect_capture_attribution` master switch (a merchant who turned
attribution capture off does not get a new writer from an update), and no
WooCommerce check. **Build trap, now documented in CLAUDE.md:** the two storefront
bundles must be built in SEPARATE vite passes (`--mode landing`, chained from
every `build*` npm script) — verified 2026-08-05 that a single pass hoists the
shared module into `dist/shared/attribution-<hash>.js` and gives BOTH bundles a
top-level `import`, at which point neither loads as the classic `<script>`
`StorefrontBeacon` enqueues (it would have broken browse tracking too). Gates:
`npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`,
PHPUnit unit **738/738**, eslint/tsc clean, vitest **275/275**); integration
`StorefrontBeaconTest` **11 tests, 32 assertions**, 0 failures; full suite
**251 tests, 1464 assertions** with only the known `BuildHashTest` artifact
failure (a vite build with `emptyOutDir` removes `dist/build-hash.txt`; green
again after `composer run package:hash`). **Demonstrated on the running
store** — the dev wp-env site (connected, browse tracking off) returns
`sc-landing.js` + the `window.smailyConnectLanding` blob on a storefront request
and no `sc-runtime.js`; the integration suite pins the whole enqueue matrix
(off+connected → writer only; on+connected → runtime only; disconnected →
neither; master switch off → neither) and vitest covers the shared capture
standalone incl. the junk-rec-id refusal. Merchant docs `docs/site/index.html`:
the Campaign Intelligence attribution bullet now says the capture runs in the
browser as well, so it survives a page cache and a browse-tracking-off store
(EN + ET) — **not published** (the Estonian proofread gate). DECISIONS PRO-1767.
No version bump — ships with the next release cut._

Prior: 2026-08-05 (**PRO-1633 — a PARTIAL WooCommerce refund now reaches
the engine as a line-level RETURN.** Contract v1.8.0 §5 (synced 2026-08-04) adds
`items[].returned_at` + `return_reason_standardised` / `return_reason_raw`, and
the engine derives a FULL refund itself from `status: "refunded"` — so the only
case needing us is the PARTIAL refund, which fires no webhook and **does not
change the order status at all**, making a returned line indistinguishable from
a kept one. **Fix, two parts.** (1) One new binding —
`woocommerce_order_partially_refunded` → an `order.upsert` row
(`OrderHookHandler::on_order_partially_refunded`); a full refund still rides the
existing status hook. (2) The return fields are **DERIVED from the order's own
refunds on every build** (`OrderPayloadBuilder::returns_by_item()` reads
`get_refunds()` fresh), never carried on the event. That is the load-bearing
choice: §5 warns that items are **fully replaced on re-ingest**, so a later sync
omitting `returned_at` ERASES the return silently — deriving at send time means
the live hook, a flusher retry AND the order backfill all re-send it for free
(they all build through this class), and a store switching this on later carries
its historical returns on each order's next sync, with no refund backfill
written or needed. **Semantics:** a line is returned only when its FULL quantity
has come back (quantities accumulate across refunds; the refund that completes
it supplies the date via `IsoDate` and the reason) — the contract types
`returned_at` per LINE with no per-quantity mechanism and its consumers read it
as "the customer does not have this", so 1-of-3 stays *kept* (conservative,
under-reports rather than lies; a widening question for the engine team, filed,
non-blocking). An **amount-only** refund marks nothing. `return_reason_raw` is
WooCommerce's merchant-side refund reason (trimmed, capped at 500, omitted when
blank); `return_reason_standardised` is **never sent** — Woo has no return
taxonomy and §5 forbids guessing one from free text. Best-effort by design:
stores refunding off-platform send nothing, and NULL means "kept". A refund
DELETED in the admin leaves its return standing until the order's next sync
re-derives it (accepted; no `woocommerce_refund_deleted` binding). The **mock
now rejects a non-`Z` `returned_at` per order** (`field: items.returned_at`) —
the first datetime we put on a LINE, and the F3-21 scar says only the live
engine ever caught that class; the two REASON fields are deliberately NOT
validated (§5: never rejected). Gates: `npm run ci:strict` **exit=0** (PHPCS 0
errors, PHPStan `[OK] No errors`, PHPUnit unit **738/738**, eslint/tsc clean,
vitest **271/271**); integration full suite **246 tests, 1448 assertions**, 0
failures. **Demonstrated on the running
store** — `RecEngineOrdersTest` drives the real chain (real `wc_create_refund()`
partial refund → the real hook → the real flusher → the mock): the order status
is asserted unchanged, the refunded line arrives with a `Z`-form `returned_at` +
reason, the untouched line arrives clean, and **a later status-driven sync still
carries the same `returned_at`** (the erase case — the assertion the whole
design exists for). **LIVE-WALK COMPLETED 2026-08-05** (truth-fix: this entry
first shipped saying it had not been). The dev wp-env's stored SANDBOX API key
had been **rejected by the engine (401 `Valid API key required`)** — a
pre-existing connection problem, reproduced on a bare `ping()`, unrelated to
this change — so the walk aborted cleanly on its new health gate (nothing
created, no residue). The connection was restored by exchanging a **fresh
"Smaily Connect test" SANDBOX setup token** through the real
`SetupExchange`→`store()` path, using the new reusable secret-safe helper
`bin/exchange-setup-token.php` (token piped over STDIN into the wp-env cli
container per the CC.3 mechanic; it prints only kind/tenant_name/host/connected
and hard-aborts on tenant `MiuMjau`). Post-exchange: `ping()` healthy
(`engine_version 1.2.0`, tenant "Smaily Connect test"), the durable
`smly_rec_*` snapshot refreshed (PRO-1240/PRO-1256) so a suite run preserves
it. `RECENGINE_LIVE=1 node bin/walk-pro1633-return-signals.cjs` → **LIVE OK,
12/12 checks**: the partial refund leaves the status untouched yet enqueues a
resync, the **live engine ACCEPTS the returned line** (`{"http":200,"outcome":
"accepted"}`, `sent:1 failed:0`), `returned_at` arrives in the IsoDate `Z` form,
`return_reason_raw` carries the merchant reason with no
`return_reason_standardised`, the untouched line stays kept, a later unrelated
sync still carries the return, and 1-of-3 refunded is still kept. Engine-side
residue: two ingested sandbox orders (+ their auto-created customers);
store-side residue none (orders deleted HPOS-correctly, verified 0 rows).
Merchant docs `docs/site/index.html`:
the privacy template's "what data is used" bullet now names returns (EN + ET) —
**not published** (the Estonian proofread gate). DECISIONS PRO-1633, LESSONS
§2.24, `docs/audits/MOCK_DIVERGENCE_AUDIT.md` §3. No version bump — ships with
the next release cut._

Prior: 2026-08-04 (**PRO-1710 — a junk `?smaily_rec=` in a landing URL
no longer costs the shopper's whole order.** The engine validates an order's
`smaily_rec_id` as `z.string().uuid()` and orders validate **per order** (D6),
so ONE order carrying anything else is rejected permanently while its batch
mates go through. Our landing capture accepted any bounded token
(`^[A-Za-z0-9._-]{1,64}$`, F3-46's deliberate leniency) and the mock never
inspected the field — so a visitor arriving with a hand-typed, truncated or
crafted rec param got it cookied, stamped onto their order at checkout, and
**that order was silently lost to ingest**, with every gate green. **Fix:** one
definition of the shape (`Smaily\RecEngine\Support\RecId`, the engine's zod
regex verbatim) enforced at BOTH ends of the cookie's life — **capture**
(`LandingCapture` + its JS twin `captureUrlParams`, which writes the SAME
cookie) refuses to cookie a non-UUID, and **send** (`OrderPayloadBuilder`) drops
a non-UUID already stored on order meta and ships the order un-attributed. The
send-side half is what heals a live store: a cookie already in a shopper's
browser can't be reached by a release. The stored order meta is left untouched
(only the wire object omits the field); `smaily_vt` is deliberately NOT
uuid-typed (opaque engine token). The **mock now returns the same per-order D6
error the live route does** (`field: smaily_rec_id`, `message: "Invalid uuid"`),
closing the divergence — `docs/audits/MOCK_DIVERGENCE_AUDIT.md` §3 marked
RESOLVED. Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK]
No errors`, PHPUnit unit **729/729**, eslint/tsc clean, vitest **271/271**);
integration full suite **244 tests, 1417 assertions**, 0 failures.
**Demonstrated on the running store** — `RecEngineOrdersTest` drives the real
chain (server-side landing capture → real WC order → real classic-checkout
stamping → real flush against the mock): a junk landing leaves no meta and the
order ingests un-attributed, a genuine UUID rides through to the wire exactly as
before, and a junk value already on order meta is dropped at send; the two junk
cases **were confirmed to fail against the pre-fix code** (the meta case coming
back `failed` — the now-honest mock D6-rejecting the order, exactly the
production symptom). Merchant docs `docs/site/index.html` **unchanged** — no
user-visible surface (the rec params are engine-generated link internals).
DECISIONS PRO-1710, LESSONS §2.23. Contract §5 still types the field as a plain
`string`; typing it as a UUID there is the engine ask **PRO-1713** — not waited
on, validated against the observed live shape. §6 browse carries the same engine
constraint and is out of scope (see FOLLOW-UPS in the issue). No version bump —
ships with the next release cut._

Prior: 2026-08-04 (**PRO-1729 — the abandoned-cart reminder now carries
the shopper's NAME, so a template's first-name merge tag renders.** PRO-1680
found the reminder's product details gated on a selection option
(`smaily_connect_abandoned_cart_fields`) whose keys default to FALSE and which
**no UI has ever written**, and retired that gate for the products. The SAME
dead gate also covered `first_name`/`last_name` — likewise defaulting to false —
so a fresh install's reminder still went out with no name at all, and a Smaily
template greeting the shopper by name rendered nothing. **Owner decision (Erkki,
same rule as the products): the names always ride the reminder, with no
merchant-facing choice**; a stored selection from a version that had the
selector is ignored rather than migrated. **The one deliberate difference from
the products:** a `product_*` slot is CART state and is sent EMPTY on purpose
(overwriting all ten is what clears the previous cart from the contact), while
the names are CONTACT state, where the F3-47 omit rule governs — an ABSENT field
leaves the Smaily contact's value intact, an EMPTY one WIPES it. So a name we
don't have is **omitted, never sent as `''`**: a nameless shopper's reminder must
not erase a name the contact already carries. The source is unchanged (WP profile
name for a known user, else the checkout-captured columns on the tracker row —
so guests are covered, which the legacy pass never did). `store`/`language` keep
reading the option and were deliberately left alone: unlike the names they
default to TRUE, so they are neither unreachable nor broken on a fresh install.
Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`,
PHPUnit unit **727/727**, eslint/tsc clean, vitest **270/270**); integration full
suite **240 tests, 1394 assertions**, 0 failures (only the known
`BackwardCompatTest` suite-order skip, PRO-1771). **Demonstrated on the running
store** — `CartPipelineTest` drives the real pipeline (real cart → tracker → the
15-min sweep → CartFlusher) with only the Smaily transport faked and **no stored
selection**: a named shopper's reminder carries the name, a nameless one's omits
both fields, and a guest's checkout-typed name rides too; the two send cases were
**confirmed to fail against the pre-fix code** (`null` where the name belongs),
as were 3 of the unit cases. Merchant docs `docs/site/index.html` **unchanged** —
it already describes the reminder as carrying the shopper's name, and there is no
field-selector UI documented. DECISIONS PRO-1729 (addendum to PRO-1195/PRO-1680),
LESSONS §2.21 extended (retiring a dead switch means auditing every field it
gated; "always send" is not one uniform rule — re-derive empty-vs-omit per
field). No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1686 — a freemium Smaily account is now told
that the PACKAGE is the cause, instead of being told two things it is not.**
When an account moves to a package without API access, the health notice used to
say *"the Smaily API has been unreachable for over an hour — … paused until the
connection recovers"* and the connection test said *"Smaily did not accept those
credentials"*. Both false: Smaily is up, the credentials are fine, waiting
changes nothing. **Probed live on a real freemium account (2026-08-04):** every
endpoint the plugin uses (`autoresponder.php`, `contact.php` read + `list=1`,
`history.php`) answers `HTTP 403 {"code":227,"message":"A paid package is
required."}` — Smaily's documented *Paid Plan Required* code, a POSITIVE signal
nothing else produces — and answers it identically for the correct credentials, a
wrong password, a wrong username and no `Authorization` header at all, because
the package check runs BEFORE authentication (so while the package blocks, the
credentials cannot be checked at all — the message says so). A wrong subdomain
answers `404` with an empty body. **Fix:** one classifier
(`Smaily\RefusalReason`) reads a failed request and names the cause —
`plan_blocked` (code 227) / `credentials_rejected` (any other 4xx bar 429,
incl. the 404) / `unreachable` (429, 5xx, transport); `ApiException` carries
Smaily's own body code, `Client::test_connection(): bool` became
`check_connection(): string`, and both merchant surfaces phrase per cause: the
Test-connection error string and the notice keys `smaily_plan_blocked` /
`smaily_credentials_rejected` / `smaily_down`. A refusal Smaily states outright
is raised at the NEXT health check rather than after the hour's grace; only the
"might be a blip" case still waits. Nothing is stored — the cause is recomputed
every run, so a restored package clears the notice by itself with nothing
re-entered. RetryPolicy (PRO-1685) is untouched: a plan-blocked 403 is already
correctly permanent there. **Demonstrated LIVE** through the real plugin path
(`bin/walk-pro1686-plan-block.php`, credentials piped over STDIN inside wp-env):
`connection_check: plan_blocked`, the Test-connection answer, and the rendered
admin notice naming the package. Gates: `npm run ci:strict` **exit=0** (PHPCS 0
errors, PHPStan `[OK] No errors`, PHPUnit unit **725/725**, eslint/tsc clean,
vitest **270/270**); new `tests/Integration/SmailyPlanBlockedNoticeTest.php`
**OK, 4 tests** — **3 of its 4 cases fail against the pre-fix code**. Merchant
docs `docs/site/index.html` updated in BOTH languages (a package bullet in the
Smaily connection errors list, the proactive-health-notices note, and the Event
Log bullet — a failed row's reason now carries `(Smaily code 227)`). `.pot` /
`-et.po` rebuilt via `bin/build-i18n.sh` with Estonian translations for the four
new strings. DECISIONS PRO-1686. **Open: human acceptance** — restoring the plan
on the live account and re-running the walk (the plugin half is proven by the
restore test). No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1743 — a wizard-configured store can COLLECT
the contact fields it ticked again.** PRO-1683/PRO-1684 taught the sync to read
the merchant's field selection in both stored shapes; two readers in the legacy
tree were left doing `array_keys( array_filter( $option ) )`, which on a
wizard-configured store (the selection is a LIST of names there) matches
nothing. Consequences: `Profile_Settings::filter_enabled_fields()` printed **no
Phone / Gender / Birthday input at all** on the WordPress profile, the
WooCommerce account form and the checkout — so the store could not collect the
very meta the sync had just been fixed to send (live whenever WooCommerce is
active and credentials are saved) — and
`Subscriber_Synchronization::order_optin_subscriber()` sent a guest who ticked
the checkout newsletter box to Smaily **without an email address** (reachable
until the wizard finishes; LegacyHookBridge strips those hooks afterwards).
**Fix:** both read `SubscriberPayloadBuilder::effective_selection_legacy_keys()`
— the SAME interpreter, re-expressed in the legacy key names those readers speak
(`user_dob`, not the contact field's `birthday`), so a third shape drift cannot
split them again. `store_url`/`user_email`/`language` are always in that answer:
the legacy settings page forced all three on, and in the new namespace they are
not a merchant choice (email + store unconditional, language via
ContactLanguageResolver). The legacy namespace calling into the new one is the
existing bridge pattern (`Cron` → `ContactLanguageResolver`/`AutomationRouter`),
not a new mechanism. **A store configured before the wizard is unchanged**,
including that an unticked box stays unticked. Gates: `npm run ci:strict`
**exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit unit **714/714**,
eslint/tsc clean, vitest **270/270**); integration full suite **234 tests, 1367
assertions** with the only failure `BuildHashTest` (the gitignored
`dist/build-hash.txt` artifact was missing — green after `composer run
package:hash`), incl. the new `tests/Integration/SubscriberFieldFormsTest.php`
(**OK, 6 tests**) which renders the REAL form hooks (`show_user_profile`,
`woocommerce_edit_account_form`, `woocommerce_checkout_fields`) and drives the
REAL guest opt-in on the running store with only the Smaily transport faked —
**2 of its 6 cases fail against the pre-fix code** (no inputs printed; a POST
with no email). Not covered server-side: the checkout page's own conditional
tags (the documented harness limit), so "the input renders on a real checkout
screen" stays a manual pilot check. Merchant docs `docs/site/index.html`
**unchanged** — it already describes ticking additional fields, which is what
now happens. DECISIONS PRO-1743. Follow-up left open at the time: `Data_Handler::
get_user_data()` still read the option as a map (registered-user opt-in,
pre-wizard window only) — closed by PRO-1772, above. No version bump — ships
with the next release cut._

Prior: 2026-08-04 (**PRO-1769 — the contact import stopped after its
first 100 contacts and reported success; it now walks the whole user table.**
`BackfillJob::fetch_users_after()` asked `get_users()` for the FIRST batch every
tick and pruned `ID > cursor` in PHP, so tick 2 re-read page one, filtered it
empty, and the empty page (the walk's "no users left" signal) marked the job
`completed`. **Reproduced on the dev store first**, through the real REST
`/backfill/start` + real Action Scheduler ticks with the Smaily transport faked:
151 users / 150 opted in → 99 contacts POSTed, row `completed`,
`/backfill/status` = `{"status":"completed","percent":66,"synced":99,
"audience_estimate":150}`. **Why upgraded stores looked fine:** a store whose
user table fits in one batch (most of them — and every test we had, the
integration walk used `process_batch( 200 )` on ~10 users) is correct, because
its first page is also its last; on a bigger store the loss is silent — the
panel says completed, the first 100 contacts do arrive, and later signups arrive
via the live hooks. It never healed itself either: the daily refresh restarts at
cursor NULL and re-walks the same page. **Fix:** page in SQL like the rec-engine
backfills (`SELECT ID FROM wp_users WHERE ID > %d ORDER BY ID ASC LIMIT %d`, the
`CustomerBackfillJob::fetch_ids_after()` template) and hydrate that id page via
`get_users( include )`; cursor / walked count / last-page test all read the id
page. Audience semantics unchanged (PRO-1742 switch + F3-48 presets via
`ContactAudience`, PRO-1715's empty-audience fast path). **Re-demonstrated on the
dev store:** 150/150 opt-ins POSTed (`pro1769_001..150`), `processed 151/151`,
`percent 100`, 2 ticks; a single-page store (6 users) still finishes in one tick
with `6/6` walked, `5` synced. Gates: `npm run ci:strict` **exit=0** (PHPCS 0
errors, PHPStan `[OK] No errors`, PHPUnit unit **711/711**, eslint/tsc clean,
vitest **270/270**); integration **228 tests, 1329 assertions**, incl. the new
`ContactBackfillAudienceTest::test_a_store_with_several_pages_of_users_syncs_every_audience_member`
(fails on the pre-fix code) — the one failure in the full-suite run
(`BackwardCompatTest::test_legacy_subscriber_sync_is_stripped_after_wizard_finish`)
is **pre-existing suite-order noise**: it fails identically on the unmodified
tree and passes when run alone. Merchant docs `docs/site/index.html`
**unchanged** — it never described the paging, so nothing there became false.
DECISIONS PRO-1769. No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1717 / PRO-1718 / PRO-1720 — three approved
wizard trims (Jane's PRO-1645 review, approved by Erkki).** UI only, no PHP
touched. **PRO-1717:** step 3 no longer pitches the Campaign Intelligence
automations to a store that hasn't connected Campaign Intelligence —
`EngineAutomationsSection` renders nothing when the engine is not connected in
the wizard, because the very next step IS the Campaign Intelligence overview
plus connection form. A connected store's section is unchanged; **Settings keeps
its upsell** (no "next step" there — its CTA is the pointer to the tab).
**PRO-1718:** the "Open Smaily dashboard →" link (ET *"Ava oma Smaily konto →"*,
PRO-1746's wording, unchanged) moved from the last step to **step 1**, where the
merchant is told to go create the API user; it is **removed from the last step**
and renders once a subdomain is known, since that subdomain IS the account
address. The overview's "Open dashboards" card had only the Campaign
Intelligence button left, so it now appears only when that dashboard exists.
**PRO-1720:** the Event Log advisory paragraph is gone from the last step — a
failing event raises its own admin notice linking to the log, so nothing there
needs watching by hand. `.pot`/`-et.po` rebuilt via `bin/build-i18n.sh` (the two
Event Log msgids dropped, the moved link's Estonian translation preserved).
Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`,
PHPUnit unit **710/710**, eslint/tsc clean, vitest **270/270** — 6 new/rewritten
component tests covering each surface both ways); admin bundle rebuilds clean.
**Integration NOT run** — no PHP changed. Merchant docs `docs/site/index.html`
**unchanged**: it describes neither step 3's Campaign Intelligence section, nor
the last step's dashboard link, nor the Event Log advisory, so nothing there
became false. DECISIONS PRO-1717/1718/1720. No version bump — ships with the
next release cut._

Prior: 2026-08-04 (**PRO-1715 — a contact import with nothing to sync
finishes on its own instead of spinning.** On a store where nobody is in the
sync audience (fresh install with only the administrator, consent mode with no
opt-ins, or the PRO-1742 "Sync contacts to Smaily" switch off), **Start import**
left the progress panel loading until the merchant cancelled by hand — Jane's
PRO-1645 point 1. **Reproduced on the running dev store first**: the job is fine
(it completes as soon as a batch runs), but the only exit from `running` was an
Action Scheduler tick, and on a quiet store that tick is minutes away (five were
queued unprocessed) with nothing to show meanwhile. **Fixed by deciding it up
front**: `BackfillJob::start()` asks `has_empty_audience()` (the existing
`ContactAudience::count_audience()`, the same definition the walk filters on)
and closes the row as `completed` immediately — `processed_count = total_count`
(with an empty audience every user would have been skipped anyway),
`synced_count = 0` — and leaves the freshness markers alone; the REST
`/backfill/start` and the daily refresh schedule the first tick **only while the
row is still running**, so nothing reopens a finished job. The admin panel stops
assuming a start means "running" (it adopts the response status and pulls the
real payload when terminal) and Step 2 names the outcome: *"Nothing to import —
no contacts match your synchronization settings."* (ET *"Pole midagi importida —
…"*, `.pot`/`-et.po` rebuilt via `bin/build-i18n.sh`). **A store with contacts is
untouched** — verified live on the same store: with one opted-in user the route
still answers `running` with one tick scheduled. Gates: `npm run ci:strict`
**exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit unit **710/710**,
eslint/tsc clean, vitest **264/264**); integration **OK (227 tests, 1318
assertions)**, incl. the new
`ContactBackfillAudienceTest::test_starting_with_an_empty_audience_finishes_the_run_without_a_tick`
(fails on the pre-fix code). Merchant docs `docs/site/index.html`: the import
status table gains the new outcome line in BOTH languages — **needs an ET
proofread + re-publish over FTPS by the orchestrator**. DECISIONS PRO-1715. No
version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1716 — the "Force opt-in on automation
triggers" setting is retired; automation triggers behave uniformly.** The
advanced Step-2 toggle (F3-48.4, visible only under the legitimate-interest
preset, default OFF) let a store send `force_opt_in=true`, so a welcome /
first-order / abandoned-cart trigger would override an unsubscribe the contact
had made in Smaily. Jane's PRO-1645 review asked for it to go on the PRO-1678
ground rule — a trigger may enrol someone Smaily has never seen, but never
overrides an opt-out. **The surviving behaviour is the default state's**, so a
store that never touched the toggle sees NO change; **the one behaviour change
is for a store that had it ON** — its triggers stop re-subscribing
unsubscribed contacts, which is the point of the work. Removed: the
`ContactSyncMode::automation_force_opt_in()` policy + its option constant
(`AutomationRouter` now passes `false` outright), the `SettingsEndpoint` write,
the `EnvDetector` boot field, and the Step 2 `Toggle` with its two strings
(`.pot`/`-et.po` rebuilt via `bin/build-i18n.sh`). The stored option is left in
place as a harmless orphan — no migration, and `uninstall.php` already sweeps
every `smly_plus_*` row by prefix. The REST route stays tolerant: a cached
pre-PRO-1716 admin bundle still posting `automationForceOptIn` saves fine
(unknown keys are simply not read) and never rewrites the option.
`Client::trigger_automation()`'s default flipped `true` → `false` to match its
two callers. Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan
`[OK] No errors`, PHPUnit unit **708/708**, eslint/tsc clean, vitest
**262/262**); integration **226 tests, 1305 assertions**, the only failure a
missing `dist/build-hash.txt` build artifact (regenerated with `composer run
package:hash` → `BuildHashTest` green). Merchant docs `docs/site/index.html`
needed NO change — it never documented the setting. DECISIONS PRO-1716;
`docs/CONTACT_SYNC_MODES.md` + the Shopify parity doc amended. No version bump
— ships with the next release cut._

Prior: 2026-08-04 (**PRO-1746 — the wizard and Settings speak Jane's
approved copy, in both languages.** A strings-only pass over the admin UI
against the approved "Copy and translations" document: the menu item is
**Initial setup** (ET *Algseadistus*), **Subscribers → Contacts** everywhere it
names an audience (step rail, Settings tab, Step 6 summary, the ET *tellijad →
kontaktid* canon), **Field mapping + backfill → Synchronization settings**, the
platform-neutral **Automated letters** for step 3 (ET *Automaatikad* — no
"WooCommerce'i" prefix), **Integrations → Forms and RSS feed** (rail
"Subscription forms and RSS", ET *Vormid ja RSS*) and **Done → Overview**, plus
per-step clarity rewrites: the API-credential instructions now say where in
Smaily to create the API user (with a docs link), the sync-mode descriptions are
written in contact/consent language, the abandoned-cart trigger states the
15-minute check, the transactional section emphasises the separate account
(support@smaily.com to get one), the CI step points at the connection URL, and
the "Initial backfill / Start backfill" pair became "Initial contact import /
Start import". Estonian is carried in `languages/smaily-connect-et.po` (97
msgstr set or updated, incl. the previously untranslated PRO-1504 transactional
strings); `bin/build-i18n.sh` rebuilt the `.pot`, `.mo` and the admin-bundle
JSON catalog. **Deliberately NOT touched** (owned elsewhere, landed change
wins): the welcome-trigger description (PRO-1682 — the doc still quotes the
pre-PRO-1682 wording), the Campaign Intelligence section's existence (PRO-1717
copy-edited only, not removed) and Step 6's Event Log paragraph (PRO-1720).
**No behaviour change** — labels, descriptions and translations only; no logic,
option keys or REST fields moved. Merchant docs `docs/site/index.html` follows
in BOTH languages (step/tab names, the Initial setup menu item, Start import).
Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No
errors`, PHPUnit unit **708/708**, eslint/tsc clean, vitest **261/261**);
integration suite not re-run — the only PHP touched is the two menu-label
strings in `admin/wizard.php`. Verified on the running store: the built ET
catalog resolves all six step titles + the Settings heading, and the rebuilt
`.mo` resolves the menu item to *Algseadistus*. **Estonian proofread gate
PASSED (Erkki accepted the copy as-is, 2026-08-04)** and the merchant docs
site was **published over FTPS the same day** (live at
`https://smaily.com/connect-woo/`, verified carrying the day's changes —
marker field names + new terminology; FTPS credentials now live durably at
`~/.local/state/smaily-connect/ftp-smaily-connect-woo`, mode 600, so the
`/tmp` hand-off is only a fallback). No version bump — the plugin-side
strings ship with the next release cut._

Prior: 2026-08-04 (**PRO-1742 — switching "Sync contacts to Smaily"
off actually stops the sync.** The wizard/Settings switch has always been
stored as `smaily_connect_subscriber_sync_enabled`, but the live sync gated on
`smly_plus_subscriber_sync_enabled` — a key **no version of this plugin has
ever written** (checked across the whole git history). Both default to on, so
nothing looked wrong day to day; a merchant who switched contact sync off kept
sending. **Reproduced first on the running store**: the switch saved off
through the real REST settings route, then a new account + opt-in + profile
edit still POSTed two contact rows, and the contact backfill POSTed too.
**Fixed by making the legacy key canonical** — it is the one every version has
written, so an upgraded store's stored choice is honoured with no migration —
**read everywhere through one accessor**, `ContactSyncMode::sync_enabled()`:
the four live `HookHandler` gates, `ContactAudience`, and the wizard's
hydration; `SettingsEndpoint`'s constant is now defined AS
`ContactSyncMode::OPTION_SYNC_ENABLED`, so the key has one spelling in the
plugin, and the dead `smly_plus_` constant is gone. **The backfill honours it
via `ContactAudience`** (switched off ⇒ empty audience whatever the mode says,
so the mass walk sends nothing and the "about N will be synced" estimate says
0) — which is also what the legacy daily cron did with this same option.
**A second layer surfaced while fixing it:** `update_option( $key, false )` on
a never-saved option concludes "nothing changed" and writes NOTHING, so a
merchant switching the sync off during the initial wizard lost the answer
entirely against a default of ON; the save route now stores `'1'`/`''`, the
legacy on-disk shape. **Automations stay decoupled** (welcome / first-order /
abandoned cart keep their own toggles) — exactly what the merchant docs
already promise, so `docs/site/index.html` needed no change. **The
demonstration** (`tests/Integration/ContactSyncToggleTest.php`) drives the REAL
settings route, the REAL account hooks and the REAL backfill on the running
store with only the Smaily transport faked, both ways, plus a legacy fixture
written by `Support\LegacySettingsPage`; 3 of its 5 cases fail against the
pre-fix code. Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan
`[OK] No errors`, PHPUnit unit **708/708**, eslint/tsc clean, vitest
**261/261**); `sg docker -c "composer run test:integration"` **OK (222 tests,
1294 assertions)** with 1 pre-existing skip, dev sandbox tenant "Smaily Connect
test" restored post-run. Human acceptance still open: a merchant-visible pass
on a real store (switch off, change a customer, confirm nothing arrives in
Smaily). No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1682 — only a shopper's account triggers
the welcome automation.** The trigger fired on ANY `user_register`: a staff
account added in wp-admin, an account a membership/forum plugin created —
all of them became opted-in marketing contacts, with no customer
relationship for a legitimate-interest basis to rest on, and enrolment
can't be undone once a trigger fires. **The welcome now fires from
`woocommerce_created_customer` only** — WooCommerce's own "a shopper got an
account" signal, which every shopper flow raises (classic checkout, block
checkout, My Account registration, the order-confirmation create-account
block all go through `wc_create_new_customer()`), and which wp-admin's Add
New User and a plain `wp_insert_user()` never raise. **The signal is the
FLOW, not the role:** a role allowlist was rejected — `customer`-only drops
a store's wholesale/VIP roles and widening it to `subscriber` re-admits the
very accounts this is about — and because `woocommerce_new_customer_data`
lets a plugin swap the role while still firing the hook, custom shopper
roles keep working precisely because no role is ever consulted. Both hooks
fire in one request for a WooCommerce-created account; the existing
per-request dedupe collapses them to exactly one enrolment. Contact sync is
UNCHANGED (still `ContactAudience`'s mode-aware decision, F3-48), and
F3-20's rec-engine A-filter is deliberately untouched — that decision is
about training-data noise, not marketing lists. Documented escape hatch
`smaily_connect_welcome_eligible` ( $eligible, $user_id, $source ) widens or
narrows it per store. Known limit, stated not engineered around: a plugin
that creates accounts THROUGH `wc_create_new_customer()` is
indistinguishable from a shopper and still triggers. **The demonstration**
(`tests/Integration/WelcomeTriggerAudienceTest.php`) creates REAL users
through the REAL paths on the running store — `wc_create_new_customer()`
plain, with checkout's argument shape, and with a custom `wholesale` role
swapped in; `wp_insert_user()` as `administrator`/`editor`; `wp_insert_user()`
with the default role — and asserts which reach the Smaily transport on the
welcome workflow, with only the transport faked. Confirmed to pin the change
by restoring the `user_register` default to eligible (2/6 integration + 2
unit fail). Wizard trigger description + merchant docs `docs/site/index.html`
corrected in BOTH languages (the Welcome bullet said "a new customer opts
in"). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No
errors`, PHPUnit unit **705/705**, eslint/tsc clean, vitest **261/261**);
`sg docker -c "composer run test:integration"` **OK (217 tests, 1275
assertions)** with 1 pre-existing skip, dev sandbox tenant "Smaily Connect
test" restored post-run. Human acceptance still open: a non-shopper account
type demonstrated on a real running store. No version bump — ships with the
next release cut._

Prior: 2026-08-04 (**PRO-1684 — a store upgraded from an older
Connect version syncs the fields it always did, and the wizard shows them.**
The legacy settings page stored the merchant's selection as a MAP
(`Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS` keys → bool, written by
`Sanitizer::sanitize_subscriber_sync_fields()` — deleted with the legacy
view layer at F3-45, 9a02618); `SubscriberPayloadBuilder` reads a LIST of
enabled names, so on an upgraded store the map's `'1'`/`''` values matched
nothing and every optional field silently vanished — while the wizard showed
the OPPOSITE, because that map reaches the browser as a JS object whose
missing `.length` made `hydrate.ts` fall back to "all ten boxes ticked".
**Fixed read-side, like PRO-1683** (no write-migration, so it heals however
the plugin was updated and the original stored value is never destroyed):
`SubscriberPayloadBuilder::interpret_selection()` understands both shapes,
and `effective_selection()` is now the single source for BOTH readers — the
payload builders AND `EnvDetector::saved_settings()` — so a tick the merchant
sees always means the field is being sent. **The map's VALUES are honoured**
(a legacy `false` is a real "don't send this", the same answer the legacy
sync gave); the legacy→current key map is documented in DECISIONS —
`user_dob`→`birthday` is the one real rename, and `store_url`/`user_email`/
`language` map to nothing because they were never optional (§1 / F3-47).
**An unreadable selection is admitted, not guessed**: neither shape ⇒ the
documented default (every cross-channel field on, NOT the bare minimum) plus
a dismissible `notice-warning` telling the merchant to re-save
(`NotificationManager::SYNC_FIELDS_ADVISORY_KEY`, the F3-50 advisory
pattern); a never-saved option is a fresh install and is never nagged.
`hydrate.ts` now treats an empty list as an answer (`Array.isArray`, not
`.length > 0`) — the legacy default of nothing ticked IS an empty selection.
Nothing on the wire changed (field names and value forms untouched, PRO-1678
ground rule; a field with no source value is still OMITTED, never empty).
**The demonstration** (`tests/Integration/SubscriberSyncFieldSelectionTest
.php`, six new cases) seeds the option through the REAL legacy writer —
`Support\LegacySettingsPage`, its method body copied verbatim from
`9a02618^` and verified to return arrays identical to the historical class
over every shape a merchant could post — then drives the real sync pipeline
and the real wizard hydration with only the Smaily transport faked; plus a
new `admin/src/state/hydrate.test.ts` for the browser half. Confirmed to pin
the change by disabling the legacy branch (4/11 integration fail) and by
restoring the `.length` check (2/3 vitest fail). Merchant docs
`docs/site/index.html` updated in BOTH languages (Settings → Subscribers →
Fields to sync: upgrades keep the old selection; the notice when it can't be
read). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK]
No errors`, PHPUnit unit **702/702**, eslint/tsc clean, vitest **261/261**);
`sg docker -c "composer run test:integration"` **OK (211 tests, 1251
assertions)** with 1 pre-existing skip, dev sandbox tenant "Smaily Connect
test" restored post-run. Human acceptance still open: confirming the
interpretation against a genuine production store's stored option value. No
version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1683 — ticking Phone and Gender in the
setup wizard now actually syncs them.** The wizard saved the merchant's
selection under `phone` / `gender` while the sync has always read
`user_phone` / `user_gender` (spec/FIELD_MAPPING.md §2), so
`SubscriberPayloadBuilder`'s supported-fields intersection dropped both
before a payload was ever built — no error, no notice, the checkbox simply
did nothing, in every contact sync mode and on both the live and backfill
paths. **Fixed on the WIZARD side** (`DEFAULT_SYNC_FIELDS` +
`Step2Subscribers` labels now use the canonical names): the names the sync
expects are the field-naming standard AND what merchants' existing Smaily
segments and templates reference, so renaming them on the wire is a
one-way door (the PRO-1678 sweep ground rule). **The upgrade path is a
read-side alias, not a migration** (`SubscriberPayloadBuilder::
canonical_fields()`, `phone`→`user_phone`, `gender`→`user_gender`): a
store that saved its selection pre-fix keeps sending the same fields
without re-saving, and it heals however the plugin was updated instead of
depending on an upgrade hook a ZIP install may never fire. `EnvDetector::
saved_settings()` canonicalises the same way so the checkbox shows ticked
— otherwise the merchant could never turn the field back OFF. Only string
VALUES are aliased and keys are preserved, so the legacy associative
option shape passes through byte-identical (PRO-1684 is untouched, neither
fixed nor worsened). Also dropped the per-instance memo of the selection —
a handler registered at `init` outlives a settings save, and it was making
one test pass for the wrong reason. Nothing on the wire changed: values
still ship as `user_phone` and the legacy `Female`/`Male` gender enum, and
a field with no source value is still OMITTED (absent preserves what
Smaily holds, empty would wipe it). **The demonstration**
(`tests/Integration/SubscriberSyncFieldSelectionTest.php`) drives the real
`POST /settings` save route + the real pipelines (live contact sync and
`BackfillJob`) with only the transport faked, and READS the wizard's
checkbox list from `admin/src/state/types.ts` rather than copying it — a
copy would keep passing while the shipped wizard saved discarded names.
`SubscriberPayloadBuilderTest` adds the same cross-file pin to the unit
gate. Confirmed to pin the change by reverting each half (wizard half 3/5
fail, upgrade half 1/5). Merchant docs unchanged — `docs/site/index.html`
never claimed these fields were unavailable; its "tick any additional
fields (name, phone…)" line simply becomes true. Gates: `npm run
ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit
unit **688/688**, eslint/tsc clean, vitest **258/258**); `sg docker -c
"composer run test:integration"` **OK (205 tests, 1221 assertions)** with
1 pre-existing skip, dev sandbox tenant "Smaily Connect test" restored
post-run. No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1681 — every automation trigger now marks
the contact it enrols.** A contact Smaily holds only because a store
automation enrolled them was indistinguishable from someone who subscribed
themselves: of the three store-run triggers only abandoned cart sent anything
(`is_abandoned_cart` — a legacy TEMPLATE flag, not a record that the
automation ran), welcome and first order sent nothing, so a merchant could
neither target nor exclude the automation-touched group in a Smaily segment.
**Decided by Erkki (2026-08-04) and built exactly so:** each trigger writes
its OWN contact field carrying WHEN that automation last ran —
`welcome_automation_at`, `first_order_automation_at`,
`abandoned_cart_automation_at` — value `Y-m-d H:i:s` in **UTC**, from the one
place that decides it, new `Smaily\AutomationMarker`. Format chosen to match
the only other date+time already on the Smaily contact wire
(`first_registered`, raw `user_registered`) and because it sorts
lexicographically, which is what lets a Smaily segment compare it to a date —
deliberately NOT the rec-engine's `IsoDate` Z-form (a different wire, F3-21).
**Written on EVERY run, last-writer-wins** — the semantics are "this
automation ran, most recently at T", NOT entry origin; an already-subscribed
contact gets it too (accepted, the issue's entry-origin wording was waived).
Stamped when the trigger FIRES (payloads build at enqueue), so a retry
resends the moment the store event happened. **Nothing existing changed:**
`is_abandoned_cart` keeps its exact name/meaning and the marker rides
alongside it in the same payload; the `product_<field>_1..10` matrix is
untouched; a trigger writes only its own field and an automation that didn't
fire sends nothing at all (absent preserves whatever Smaily holds, `''` would
wipe — F3-47 rule 2). A plain `contact.sync` carries no marker. The
transactional triggers deliberately have none (a receipt, not an enrolment).
**The demonstration** (`tests/Integration/AutomationMarkerPipelineTest.php`)
drives the three REAL pipelines on the running store — `user_register` →
welcome, `woocommerce_store_api_checkout_order_processed` → first order, cart
tracker → sweep → `CartFlusher` → abandoned cart — with only the Smaily
transport faked, asserting each marker's exact name and UTC format ON THE
WIRE, that `is_abandoned_cart` + the product matrix are unchanged, and that
the contact syncs in the same flush carry NO marker. Confirmed to pin the
change by reverting the three call sites (3/3 fail). `AutomationMarkerTest`
asserts the three names literally, so a rename is a failing test rather than
a merchant's silently broken segment. Merchant docs `docs/site/index.html`
updated in BOTH languages (the field names + "last run, UTC" semantics under
Settings → Automations → store-run triggers). Gates: `npm run ci:strict`
**exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`, PHPUnit unit
**686/686**, eslint/tsc clean, vitest **258/258**); `sg docker -c "composer
run test:integration"` **OK (200 tests, 1189 assertions)** with 1 pre-existing
skip, dev sandbox tenant "Smaily Connect test" restored post-run. Human
acceptance still open: building an actual segment on these fields in a real
Smaily account. No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1685 — a Smaily refusal that can never
succeed now stops being retried, and the merchant is told.** `Flusher` /
`CartFlusher` caught EVERY `ApiException` as transient and called
`record_attempt()` on a counter nobody read, so a permanent refusal (401
revoked credentials, 403, 404 deleted workflow, 422 rejected address) was
re-POSTed every 60s forever: the queue grew for as long as the condition
lasted, the oldest-first drain kept handing those rows the batch slots ahead
of fresher work, and since the "N sync events failed" notice counts only
`status = 'failed'`, the merchant was never told. **The policy was already
written** — `ApiException`/`Client` docblocks ("4xx no-retry, 429 honour
Retry-After, 5xx exponential backoff"), `EventQueue::record_attempt()` ("when
attempts reaches the policy ceiling the caller flips the row to
STATUS_FAILED"), spec `PLUGIN.md` §8 ("max 5 attempts") — and simply never
applied on the Smaily side (the rec-engine side has applied its half since
F3-18). **New `Smaily\RetryPolicy`** is the one place that decides: 4xx bar
429 → `mark_failed` at once (`permanent_http_<code>: …`); everything else
(5xx, 429, transport error) → retried on the rec queue's ladder (1m, 5m, 15m,
1h, 6h) or for exactly the `Retry-After` Smaily sent (capped 6h), to 5
attempts, then `mark_failed` (`retry_limit_exceeded after 5 attempts: …`).
`Client::request()` now parses `Retry-After` onto the exception (delta-seconds
only); migration **010** adds `next_retry_at` + `idx_status_retry` to
`smly_plus_event_queue`, mirroring the rec queue (NULL = due now, so existing
rows are unaffected). **Deliberately marketing rows only:**
`TransactionalFlusher` keeps its PRO-1519 time ceiling and passes no backoff
(a pending transactional row suppresses the customer's native WC email while
it waits), which is why the backoff argument defaults to 0. **Bias on
purpose:** anything not recognisably a permanent refusal — including a
transport error with no status — counts as temporary; mis-classifying
recoverable work as permanent is the worse mistake, and either way the Event
Log recovery path bounds it (`reset_failed()` now clears status + attempts +
the retry park for ANY row in this queue; `QueueJanitor` prunes a failed row
only after 90 days, `pending` never). **The demonstration**
(`tests/Integration/SmailyRetryPolicyTest.php`) drives the real queue + the
real flush hook with only the transport faked: a 401 fails once and is never
re-POSTed; the failed row reaches the health check's `failed_events` notice; a
500 is parked ~60s and the immediate next tick skips it, the following failure
waits 300s; a 429 waits the 900s it was asked to; a row behind a doomed one
still sends; a ceiling row reads `retry_limit_exceeded` through the Event Log
REST route; Retry revives it to `pending`/attempts 0/no park and it then
sends. Confirmed to pin the fix by reverting both halves (7/7 fail). Merchant
docs `docs/site/index.html` updated in BOTH languages (the new `Last error`
values + "retried a few times with growing gaps, not every minute forever";
the stale "a Smaily contact send can't be replayed" line corrected — it can,
the payload is stored). Gates: `npm run ci:strict` **exit=0** (PHPCS 0 errors,
PHPStan `[OK] No errors`, PHPUnit unit **683/683**, eslint/tsc clean, vitest
**258/258**); `sg docker -c "composer run test:integration"` **OK (197 tests,
1166 assertions)** with 1 pre-existing skip, dev sandbox tenant "Smaily Connect
test" restored post-run. No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1680 — an abandoned-cart reminder now always
carries the cart's products.** `CartPayloadBuilder` read the
`product_<field>_1..10` slots out of the merchant field-selection option
(`smaily_connect_abandoned_cart_fields`) whose `product_*` keys ALL default to
false and which **no UI writes** — so on a fresh install the reminder went out
with an empty product matrix (an abandoned-cart email with no cart in it); only
a store carrying a value forward from a version that had the selector sent
anything. **Owner decision (Erkki, recorded in the issue):** product details are
always sent, no merchant-facing choice — `product_fields()` now fills every
`PRODUCT_KEYS` entry unconditionally and never reads the selection, so a stored
selection from an older version is ignored rather than migrated. Adding a
selector UI was explicitly rejected. The address fields (`store`, `language`,
`first_name`, `last_name`) still read the option — untouched. This aligns the
cart builder with `TransactionalPayloadBuilder`, which already sent its whole
matrix unconditionally. **What did NOT change, deliberately:** the wire still
carries all 10 slots with the unused ones as `''`. That is load-bearing — the
Smaily contact keeps whatever the last send wrote, so overwriting every slot is
what CLEARS the previous cart's details; "don't render empty rows" is the Smaily
template's job, not the wire's (template design is out of scope per the issue,
as are the delay setting and a selector UI). **The "running store"
demonstration** is `tests/Integration/CartPipelineTest.php`, now running on
FRESH-INSTALL defaults (it used to seed the fields option with
`product_name`/`product_quantity` enabled, which is exactly what masked the
defect): the existing E2E case asserts every product detail reaches the mocked
Smaily transport, plus two new cases — 11 products flag `over_10_products` on
the wire with slots capped at 10, and the same shopper abandoning a SECOND
different cart gets a reminder whose slots 2..10 are all overwritten empty with
the earlier cart's products appearing nowhere. The suite was confirmed to
actually pin the fix by reverting it (3 failures). Merchant docs unchanged —
`docs/site/index.html` never described a product-details choice. Gates:
`npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan `[OK] No errors`,
PHPUnit unit **659/659**, eslint/tsc clean, vitest **258/258**);
`sg docker -c "composer run test:integration"` **OK (190 tests, 1111
assertions)** with 1 pre-existing skip, dev sandbox tenant "Smaily Connect test"
restored post-run. No version bump — ships with the next release cut._

Prior: 2026-08-04 (**PRO-1679 — the first-order automation now also
fires on the BLOCK checkout.** The trigger hung off
`woocommerce_checkout_order_processed` alone, so on a block/Store-API checkout
store — the WooCommerce default — it never fired for anyone; attribution
capture (F3-46) and order-confirmation (PRO-1518) had already been carried
across to `woocommerce_store_api_checkout_order_processed`, first-order was
missed. **Fix:** the first-order enqueue moved out of
`HookHandler::on_checkout_order_processed()` into a shared private
`maybe_enqueue_first_order( \WC_Order )` that BOTH callbacks call;
`on_block_checkout_order_processed()` now stamps attribution AND enqueues the
automation. **Contact sync is deliberately NOT repeated on the block path** —
block checkout syncs the contact from `on_checkout_block_optin`
(`woocommerce_store_api_checkout_update_order_from_request`), the only place
the Store-API opt-in flag is readable. **Double-fire guard:** both hooks fire
in the SAME request, so the existing per-request `maybe_enqueue()` dedupe on
`automation.first_order:{order_id}` caps a store where both run at one row
(pinned by a unit case AND an integration case). No gating change — the wizard
gate, the `smly_plus_first_order_enabled` toggle, the `is_first_order()`
(registered-customer-only) rule and the workflow mapping are evaluated exactly
as on the classic path: guests still never trigger, a second order still never
triggers, and a disabled/unmapped automation still sends nothing with no
shopper-visible error. Out of scope per the issue and untouched: guests ever
receiving first-order, miscounted first orders, consent logic (automation
triggers run on legitimate-interest basis). **The "running store"
demonstration** is the new integration suite
`tests/Integration/FirstOrderAutomationPipelineTest.php` (7 cases): it fires
the REAL Store-API hook with WC's own 1-arg tuple (`$order`) — the exact shape
`StoreApi\Routes\V1\Checkout::process_order_and_payment()` fires, verified
against the WooCommerce source installed in wp-env, not from memory — plus the
real 3-arg classic hook, against real WP + WC + HPOS orders, and drains the
real queue/`Flusher` into a mocked Smaily transport asserting the mapped
workflow id and the order fields on the wire. The tests were confirmed to
actually pin the fix by temporarily reverting it (2 failures, both on the block
path). Merchant docs unchanged — `docs/site/index.html` describes First order
generically ("a customer's first order"), never checkout-type-scoped, so
nothing there became false. Gates: `npm run ci:strict` **exit=0** (PHPCS 0
errors, PHPStan `[OK] No errors`, PHPUnit unit **658/658**, eslint/tsc clean,
vitest **258/258**); `sg docker -c "composer run test:integration"` **OK (188
tests, 950 assertions)** with 1 pre-existing skip, dev sandbox tenant "Smaily
Connect test" restored post-run. No version bump — ships with the next release
cut.

Prior: 2026-08-04 (**Contract re-synced byte-identical to engine
`547ad4d6` (md5 `3cebdcae…`) — v1.6.0 → v1.8.0, CC-8 pass.** The daily
`Contract staleness` workflow went red ~2026-08-01; three engine doc commits
had landed since our last sync (`21d5c0c`): `42b5386` (§2 URL example errata),
`6d7bb22` + `547ad4d` (v1.8.0 return signals). **Everything that changed, and
the follow-through call on each:**
  1. **v1.7.0 — §3 new OPTIONAL catalog field `currency`** (ISO 4217, loose
     3-uppercase-letter check, engine default `EUR`, mirrors `orders.currency`).
     We do NOT send it (`CatalogPayloadBuilder` has no currency field;
     `OrderPayloadBuilder` sends the order-level one). Additive optional field →
     no plugin change in this pass; **flagged as a follow-up** because a
     non-EUR store's catalog rows silently land as `EUR`.
  2. **v1.7.0 — §6 `smaily_rec_id` / `smaily_ctx` are now DEPRECATED,
     accept-and-ignore** (engine migrations `0080`/`0081` drop the columns; the
     4th-priority browse-attribution fallback they fed is deleted). The wire
     still accepts both without error, and our beacon client never sends them
     (F3-49 data-minimization) — `BeaconEndpoint::EVENT_FIELDS` merely
     whitelists them if a client supplies them. **Nothing breaks, so no code
     change now**; dropping the two keys from `EVENT_FIELDS` is a follow-up
     (it also retires the residual PRO-1486 spoofing surface at zero cost —
     the engine no longer reads either field).
  3. **v1.7.0 errata — §5 attribution ladder + `session_id`.** The matching
     steps now document the real 3-mechanism ladder (rec_id → visitor_token →
     email-click within a 30-day, tenant-overridable window) instead of the
     removed `session_id`/`browse_events` step, and `session_id` is described as
     accept-and-ignore (still stored on the order, no longer read by the
     matcher). `OrderPayloadBuilder` keeps sending it — the contract explicitly
     keeps accepting it "so senders don't need a plugin update". No change.
  4. **v1.7.0 errata — §2 URL-parameters example** (`utm_campaign` dropped,
     the real `utm_medium=email` added, `smaily_rec` placeholder → a
     format-valid UUID) and the matching §5/§6 example values. Doc examples
     only; `LandingCapture` reads none of the utm_* params it changed.
  5. **v1.8.0 — §5 return signals** (PRO-1633 territory): `items[].returned_at`
     documented as official (accepted since the first release, never sent by
     anyone), plus two NEW optional line fields `return_reason_standardised`
     (7-value vocabulary, never-reject — an unknown value stores as `other`,
     senders must NOT validate against a closed copy) and `return_reason_raw`
     (≤500 chars, diagnostic-only, merchant/system note — never buyer free
     text). Engine-side behavior change with no wire change: a `status:
     "refunded"` order has every line stamped returned at its own `ordered_at`,
     so full refunds need nothing from us; partial refunds (which don't change
     the WC order status at all) would need per-line `returned_at`. ⚠️ Sender
     obligation to carry into PRO-1633: items are fully REPLACED on re-ingest,
     so a later sync that omits these fields ERASES the return. **Deliberately
     not implemented in this pass** — additive optional fields, PRO-1633 owns
     the sending side.
**Mock follow-through: none required, and that is not a shortcut.** The mock
(`tests/Integration/Fixtures/mock-rec-engine/router.php`) validates the
wrapper key + the specific required fields per endpoint; it does not enforce a
closed field whitelist, and it stores each order/product verbatim
(`last_orders_payload`, `last_catalog_tags`), so the three new optional fields
pass through today exactly as the live engine takes them. Nothing this sync
changed the shape of anything we already send or receive, so **no live-walk is
required** either. **Verified follow-through the hard way**: grepped the
plugin for every renamed/deprecated field (`session_id`, `smaily_rec_id`,
`smaily_ctx`, `currency`, `utm_*`) rather than assuming — that check is what
surfaced the follow-up items above. One genuine divergence found and RECORDED
(not fixed — pre-existing, out of this pass's scope) in
`docs/audits/MOCK_DIVERGENCE_AUDIT.md` §3: the live orders route validates
`smaily_rec_id` as `z.string().uuid()` per-order (D6), while the contract types
it as a plain string, the mock never inspects it, and
`LandingCapture::is_rec_id()` accepts any bounded token — so a visitor
arriving with a junk `?smaily_rec=` value gets it cookied onto their order and
that ONE order is D6-rejected permanently. Gates: `bash
bin/check-contract-staleness.sh` **green** (`OK: … byte-identical … engine
commit 547ad4d6…`); `npm run ci:strict` **exit=0** (PHPCS 0 errors, PHPStan
`[OK] No errors`, PHPUnit unit **653/653**, eslint/tsc clean, vitest
**258/258**). Integration suite deliberately NOT re-run — the delta is
`.md`-only (contract + this file + the divergence register), no mock or plugin
code touched; judgement recorded here per the 3.3.x lesson.

Prior: 2026-08-04 (**Upstream PR #135 unblocked — `sendsmaily/main`
merged into our `main`.** The sendsmaily maintainer (kaittodesk, 2026-07-27)
reported merge conflicts blocking
https://github.com/sendsmaily/smaily-wordpress-plugin/pull/135. Erkki's call
(2026-08-04): resolve by **merging** upstream into our main — never rebase,
never force-push. Merge commit `4a3d979` (parents `4acbd80` + upstream
`87fdae9`) folds in the 17 upstream commits landed since our fork point
`a7e9f65` (#118–#134), all of them maintenance on the LEGACY plugin our
rewrite replaces. **Conflict rule: ours wins on every contested file** — the
legacy admin UI (`admin/smaily-admin*.class.php`, its partials, `admin/css`,
`admin/js`) stays deleted, `phpcs.xml` stays superseded by
`phpcs.xml.dist`, and the plugin header, `readme.txt` header,
`composer.json`/`composer.lock`, `.wp-env.json`, `.zipignore` and both
translation catalogs keep our values (our WP 6.6 / PHP 8.0 floors already
exceed the #128 bump to 6.5 / 7.4). **Upstream substance kept where it
doesn't collide:** the legacy-class quality + PHPStan fixes (#118/#119/
#129/#133), the checkout-optin / Elementor / cron / profile-field bug fixes
(#121–#126), the `release.sh` HTTP-429 recovery (#132), the wp-env dev
environment + rewritten `contributing.md` (#134 — `Dockerfile`,
`compose*.yaml`, `user.sh` accordingly deleted), and upstream's 1.6.2 /
2.0.0 changelog entries in `readme.txt` + `readme.md`. **Deliberately NOT
taken:** upstream's `phpstan.neon` (PHPStan prefers it over our
`phpstan.neon.dist` when no `-c` is passed, and it analyses the legacy tree
at level 5 against stubs we don't install → would break `composer run
analyze`); `.wp-env.min.json` was taken but raised to WP 6.6 / PHP 8.0 to
match this plugin's real floors, and the `start`/`start:min`/`stop` scripts
`contributing.md` documents were added to `composer.json`. One merge-hazard
caught by hand: `includes/smaily-helper.class.php` came out of the merge
with a DUPLICATE `namespace` declaration (upstream moved it to the top of
the file, we keep it after the docblock) — a PHP fatal that no conflict
marker flagged; fixed in the merge commit. **Gates:** `npm run ci:strict`
exit=0 (PHPCS clean, PHPStan `[OK] No errors`, PHPUnit unit **653/653**,
eslint/tsc clean, vitest **258/258**) and `sg docker -c "composer run
test:integration"` **OK (181 tests, 916 assertions)** with the dev sandbox
tenant "Smaily Connect test" restored post-run. No plugin version bump —
the merge changes no shipped behavior of the v3 tree beyond the legacy-class
maintenance listed above.

Prior: 2026-07-23 (**v3.10.0 — PUBLISHED.** MINOR bump — the pilot-driven
Transactional-emails Settings UI restructure (PRO-1540: account connection
moved to the Connection tab with its own connection test, Order/Shipping
trigger sections gated onto the WooCommerce tab only once that account is
connected, "separate Smaily account" wording, docs-site rewrite), the Event
Log "Retry all failed" reachability fix for aged failures with no fresh
24h failures (PRO-1539), PCP false-positive `slow_db_query_meta_key`
suppressions on `TransactionalGate` (PRO-1538), the contract §7
`identity/merge` errata re-sync (PRO-1534, doc/mock-only, no plugin code
touched the removed field), the PRO-1537 live-escape-probe walk script
(tooling, no shipped-code change), and doc-only upstream-merge-prep /
delta-security-audit work. No option-key or REST-field-name changes in
PRO-1540 — a store already configured on v3.9.0 keeps working, only the tab
it edits from changed. Version bumped in all four places
(`smaily-connect.php` header + `SMAILY_CONNECT_VERSION` +
`SMAILY_CONNECT_PLUGIN_VERSION`, `package.json`, `readme.txt` Stable
tag/Changelog/Upgrade Notice) plus the three test pins (`ConstantsTest.php`,
`tests/bootstrap.php`, `tests/phpstan-bootstrap.php`), committed first
(`36977dee2e364ec9af2a006896a2ad108112bbfd`, on `main`, not pushed — the
orchestrator pushes + tags). Two user-facing changelog items (see
`readme.txt` 3.10.0 entry): the transactional-emails settings relocation for
discoverability, and the aged-failure Event-Log bulk-retry fix. Builds:
`npm run build:admin && npm run build:client` (`dist/admin/admin.js`
291.70 kB, `dist/public/js/sc-runtime.js` 6.47 kB); blocks rebuilt
(`composer run install-block-modules && composer run build`); **i18n
rebuild RUN** (`sg docker -c "bash bin/build-i18n.sh"`, required this cut —
PRO-1540's new admin strings, e.g. "Use transactional emails" / "A separate
Smaily account used only for transactional sends." / the two
Connection↔WooCommerce forward-pointer strings, needed extraction into
`languages/smaily-connect.pot` + `-et.po` and the admin-bundle JSON catalog
`smaily-connect-et-464ceaab21588225a35cae9f83dfa47d.json`). `composer
install --no-dev --optimize-autoloader` → `composer run package` →
`composer install` (dev vendor restored immediately after packaging,
verified via `vendor/bin/phpunit` present). **ZIP verified**: 3.10.0 in both
version strings present in the shipped tree (plugin header, readme Stable
tag — `package.json` is a dev source file, not shipped, by design);
required present (`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`,
`blocks/*/build/*` all three blocks, `vendor/autoload.php`,
`languages/smaily-connect-et.mo` + the admin-bundle et JSON,
`composer.json`); dev artifacts/tests/docs/node_modules/admin-src/dev-vendor
absent (grep-verified); **1 148 878 B** (vs. v3.9.0's 1 147 215 B — the
small delta is consistent with the two-tab UI restructure + one new admin
component). **PCP against the built ZIP** (`docker cp` into the wp-env
`…-cli-1` container, unzipped to `smaily-connect-pkg` — never the
bind-mounted `smaily-connect` dir — `--slug=smaily-connect
--exclude-directories=vendor`, temp copies cleaned up after): **0 findings**
("Success: Checks complete. No errors found.") — the two
`slow_db_query_meta_key` warnings the v3.9.0 gate carried are now
suppressed by PRO-1538, as intended. **`ci:strict` exit=0** (PHPCS 0 errors
[only pre-existing warnings], PHPStan clean, PHPUnit unit **653/653**,
vitest **258/258** across 33 files, tsc/eslint clean). **Integration suite
RE-RUN in full**: `sg docker -c "composer run test:integration"` **OK (181
tests, 916 assertions)**, dev sandbox tenant "Smaily Connect test" correctly
restored post-run (not MiuMjau, `connected=1`). **PUBLISHED 2026-07-23**: tag
`v3.10.0` on bump commit `36977dee2e364ec9af2a006896a2ad108112bbfd`, GH release
https://github.com/erkkimarkus/smaily-wordpress-plugin/releases/tag/v3.10.0,
ZIP SHA256
`45f25d315092e87f4258084eb00d8185529a22f94396bf071914c52c8306e774`. The
upstream merge PR is now OPEN against the official repo —
https://github.com/sendsmaily/smaily-wordpress-plugin/pull/135 (v3.10.0
wholesale replacement per `docs/UPSTREAM_MERGE_PROPOSAL.md`; PRO-1196
mechanics step done; awaiting sendsmaily review + the "working repo
afterwards" answer).

Prior: 2026-07-23 (**Pre-v3.10.0 delta security audit (docs-only,
audit-and-record) + CLAUDE.md docs-site publish-mechanism note.**
- **Security audit** — addendum appended to
  `docs/audits/SECURITY_DELTA_2026-07-23.md` ("Addendum: pre-v3.10.0 delta
  `69a83b8..HEAD`") covering everything landed since this morning's v3.9.0
  gate audit: PRO-1537's escaping fix (already that file's resolved
  Should-fix, re-confirmed unchanged); the v3.9.0 release-mechanics commits;
  PRO-1540's `SettingsEndpoint` handler split
  (`save_transactional_account()`/`save_transactional_triggers()`) — capability
  check, REST-core nonce, `persist_credentials()` semantics, and
  `shippedOrderStatuses` sanitization all confirmed byte-for-byte preserved
  across the split, test-verified from both directions
  (`SettingsEndpointTest`); PRO-1534's contract/mock field-name correction
  (doc/test-only, no plugin code touches the removed field); PRO-1539's new
  "Retry all failed" button (calls the pre-existing `/events/retry` route,
  no new endpoint); PRO-1538's `phpcs:ignore` comments (comment-only). **0
  Blocker/Critical/High/Medium/Low. 0 new findings — v3.10.0 may proceed.**
  New `docs/audits/INDEX.md` row added (no gates re-run in this pass; per-
  commit `ci:strict`/integration results already recorded in this file).
- **CLAUDE.md** — documented the previously-undocumented docs-site publish
  mechanism (learned today): the live copy at
  `https://smaily.com/connect-woo/` is published over FTPS via a curl
  recipe (`--ssl-reqd -k --disable-epsv -K <cfg>`, credentials from a
  runtime-built `-K` config file, never the command line), chrooted account,
  credentials placed on request at `/tmp/smaily-connect-woo-ftp`; publish
  only after the Estonian proofread gate.

Prior: 2026-07-23 (**Three small independent fixes: PRO-1539 (Event Log
aged-failure bulk retry), PRO-1538 (PCP false-positive suppressions), PRO-1541
(merchant-docs workflow-dropdown note).**
- **PRO-1539** — `admin/src/components/settings/EventLog.tsx`'s "Retry all
  failed" control used to live only inside the 24h-failure Banner
  (`failed24h > 0`), so a store with old failed rows and no fresh failures
  (found live on MiuMjau) had no bulk-retry path at all. Added a second
  "Retry all failed" `Button` next to the status filter, shown whenever
  `failed24h === 0` but the currently-loaded rows include a failed one —
  reuses the existing `handleRetry({})` call, so the two controls never
  render simultaneously (no duplicate-button risk) and the 24h banner is
  untouched. New vitest case pins the aged path (`failed_24h: 0` + a failed
  row → control visible and calls `retryEvents({})`); the existing
  `failed24h > 0` test now also asserts exactly one "Retry all failed" button
  renders.
- **PRO-1538** — `includes/Smaily/TransactionalGate.php`'s two `'meta_key'`
  array-config entries (order/shipping confirmation per-order guard keys)
  trip PCP's `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` sniff, which
  pattern-matches any `meta_key` array key regardless of context — these are
  config values consumed as a per-order-id meta guard
  (`get_meta`/`update_meta_data` by order id), never a `WP_Query`
  `meta_query`. Added `phpcs:ignore` comments mirroring the PRO-1436 cart-code
  suppression idiom (standalone comment line, `sniff -- justification`).
  Comment-only, no behavior change.
- **PRO-1541** — `docs/site/index.html` Settings → Automations section gained
  a bilingual (EN/ET) note covering three workflow-dropdown facts: the
  dropdowns read the list live from the merchant's Smaily account on every
  settings-page load (no cache/refresh interval — reload after a Smaily-side
  change); only **active** Smaily workflows are listed (a deactivated one
  disappears); and each dropdown lists the workflows of the account its
  section uses (transactional-email sections list the separate transactional
  account's workflows, not the main account's).

Gates: `npm run ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit unit
653/653, vitest 258/258 incl. the 2 new/updated EventLog cases, eslint/tsc
clean). Integration suite (`sg docker -c "composer run test:integration"`)
**not re-run** — no PHP behavior change (Task 2 is phpcs-comment-only; Tasks 1
and 3 are React/docs-only).

Prior: 2026-07-23 (**PRO-1196 prep — upstream-merge proposal refreshed
to current truth, doc-only.** `docs/UPSTREAM_MERGE_PROPOSAL.md`'s narrative
still described the fork as shipping behind the `Update URI` guard in three
places (Status line, the PCP-finding table row, the "Distribution flips"
mechanics bullet) even though the guard was removed this morning ahead of
v3.9.0 (see the F3-35 entry below) — corrected all three to the current state
(guard gone, GitHub-Releases-only for now, stores transition onto the
wordpress.org update channel automatically once sendsmaily publishes a
version ≥ installed, no separate cutover step). Added a "Current state
(2026-07-23)" note near the top: fork at v3.9.0, GA line stable since 3.0.0,
MiuMjau pilot running, code-side punch-list complete except the two
Smaily-owned items (#6 submission mechanics, #7 pilot sign-off). **Wrote
fork-side dispositions for the three open upstream issues** (item 5,
new "Item 5 dispositions" subsection): **#120** (`.pot` manual merge) —
obsoleted by the fork's own `bin/build-i18n.sh` pipeline, its `.pot`/`.po`
become canonical at takeover, recommend closing as obsolete; **#128** (WP7 /
min-version bump) — checked against the fork's actual current floors
(`readme.txt`/`smaily-connect.php`: PHP 8.0, WP 6.6, tested to 7.0, WC 6.9)
and found they already meet or exceed #128's ask (PHP 7.4, WP 6.5) with no
real conflict, recommend closing as satisfied, flagged one confirmation point
(the fork's PHP-8.0 floor is stricter than #128's ask — Smaily should
sanity-check it against the install base); **#132** (`release.sh` HTTP-429) —
the fork carries the file unmodified since the fork point but its own release
process (the local sequence in CLAUDE.md) never invokes it, recommend it
closes as obsolete IF Smaily adopts the fork's release process, else the
patch applies cleanly and should be cherry-picked. Risks/open-questions
sections updated to match (both #120 and #128 marked resolved, open question
3 reworded to the one remaining PHP-floor confirmation). No code changed;
`.md`-only. Prior:

2026-07-23 (**PRO-1534 — contract re-synced byte-identical
(engine `21d5c0c`, md5 `2857d7cf…`).** Doc-only errata since the last sync:
§7 `identity/merge`'s response example listed a `browse_events_already_bound`
field the route never returns — the actual `merged` trio is
`browse_events_updated` / `visitor_tokens_bound` / `session_history_days`; the
field explanation and idempotency note were corrected (a repeat merge reports
`browse_events_updated: 0`, not a separate already-bound count) and an
Appendix E entry added. **Code follow-through (CC-8):** `grep`'d
`includes/`/`admin/src/` for the removed field name — no hits;
`IdentityHookHandler::on_login()` calls `Client::merge_identity()` and never
reads the response at all (fire-and-log-on-exception only), so no plugin
behavior depended on the wrong field. **Mock follow-through:** the mock's
`identity_merge` handler (`tests/Integration/Fixtures/mock-rec-engine/
router.php`) DID emit `browse_events_already_bound` alongside the correct
trio — removed it in the same commit so the mock can't mask the same drift
for a future reader. No wire-shape change on anything the plugin SENDS, so
no live-walk required. Gates: `bash bin/check-contract-staleness.sh` green
(`OK: … byte-identical …, engine commit 21d5c0c…`); mock-only PHP touch, ran
`npm run ci:strict`. Prior:

2026-07-23 (**PRO-1540 — transactional-emails Settings UI
restructured: undiscoverability fix, unreleased on main.** The pilot found
the v3.9.0 "Transactional emails" card (buried as a fourth section under
WooCommerce automations) undiscoverable. Design settled with Erkki
2026-07-23; superseded that placement, code unchanged in behavior/storage.
Split the ONE combined section into TWO, on two tabs:
  1. **Connection tab** (`Step1Connect.tsx`) — the account itself is now an
     OPTIONAL capability under the main Smaily connection:
     `TransactionalEmailsSection.tsx` (moved here from the WooCommerce
     step/tab) renders the "Use transactional emails" toggle → the same
     `CredentialBlock` (subdomain/username/password + Test connection +
     green "✓ Connected" checkmark) the main connection uses, unchanged
     component reused as-is.
  2. **WooCommerce tab** (`Step3WooCommerce.tsx`) — a NEW
     `TransactionalTriggersSection.tsx` renders the Order-confirmation /
     Shipping-confirmation `AutomationSection`s + the "counts as shipped"
     picker under their own subheading, styled like
     `EngineAutomationsSection`'s sub-section pattern — but ONLY when
     `state.transactionalConnection.kind === 'success'`; otherwise it
     renders `null` (Erkki's explicit call: no placeholder, no pointer).
Wiring: `action-to-tab.ts` moved `SET_TRANSACTIONAL_EMAILS_ENABLED`/
`SET_TRANSACTIONAL_CREDENTIALS` from the woocommerce arm to the connection
arm; `buildTabPayload.ts` moved the same two fields from the `woocommerce`
case to the `connection` case. `SettingsEndpoint::save_transactional_emails()`
split into `save_transactional_account()` (called from `save_connection()`
— toggle + credentials + the `smly_plus_transactional_connection_verified`
flag) and `save_transactional_triggers()` (called from `save_woocommerce()`
— the two trigger toggles + `smly_plus_shipped_order_statuses`). **No
option-key rename, no field-name rename** — every `smly_plus_*` option and
every REST field name (`transactionalEmailsEnabled`,
`orderConfirmationEnabled`, `shippingConfirmationEnabled`,
`shippedOrderStatuses`, `transactionalCredentials`) is byte-identical to
v3.9.0; a store already configured on v3.9.0 keeps working, only the tab
it's edited from changed. `hydrate.ts`/`EnvDetector::saved_settings()`
needed NO changes — `transactionalConnected` already fed
`deriveCredentialConnection()` the same way the main account's
`smailyConnected` does, so the Connection tab's green-checkmark state was
already server-truth-driven before this change. **Copy fix (Erkki
flagged):** the "(or sub-account)" phrasing implying a Smaily sub-account
concept (Smaily has none) is gone from `TransactionalEmailsSection.tsx`'s
credential-block description; "separate Smaily account" is now the only
phrasing used, everywhere including `docs/site/index.html`. **Docs-site**:
rewrote `#set-transactional` (EN+ET pair) to describe the new flow and name
the concrete locations (Connection tab → toggle → credentials → test →
checkmark; WooCommerce tab → Order/Shipping sections, gated), plus a
forward-pointer line each in `#set-connection` and `#set-automations` so a
reader lands on the right tab from either direction. **Tests:** component
— rewrote `TransactionalEmailsSection.test.tsx` (Connection-tab
credential-toggle behavior only now) + new
`TransactionalTriggersSection.test.tsx` (absent when not connected /
present + fetches the `'transactional'` workflow list when connected) +
new `Settings.transactionalPlacement.test.tsx` (tab-placement wiring in
the real Settings shell). Unit — split
`SettingsEndpointTest`'s combined woocommerce-tab transactional test into
a connection-tab test (account) + a woocommerce-tab test (triggers, and
asserts the woocommerce tab does NOT touch the transactional-account
options). Integration — split
`SettingsRoundTripTest`'s combined round-trip test the same way; updated
`TransactionalEmailsPipelineTest::configure()` to POST the account via
`tab: 'connection'` and the triggers via `tab: 'woocommerce'` (was a
single `tab: 'woocommerce'` POST — the account fields would otherwise
silently no-op under the new routing). **Gates**: `npm run ci:strict`
exit=0 (PHPCS 0 new findings, PHPStan clean, PHPUnit unit 653/653, vitest
257/257 across 33 files, tsc/eslint clean); `sg docker -c "composer run
test:integration"` **OK (181 tests, 916 assertions)**, dev sandbox tenant
"Smaily Connect test" correctly restored post-run (not MiuMjau,
`connected=1`) — unrelated to this change (no `smly_rec_*` option was
touched). **Not done as part of this pass:** no version bump / release cut
(task scope was the UI restructure on `main`, not a release); i18n
`.pot`/`.po` regeneration is a release-time step
(`bin/build-i18n.sh`), not part of `ci:strict`, so left untouched here —
the next release cut must run it to pick up the new/changed admin strings
("Use transactional emails", the WooCommerce-tab subheading copy, etc.).
See `docs/DECISIONS.md` PRO-1540 for the full design record.

Prior: 2026-07-23 (**v3.9.0 — build/verify/gate sequence RUN, gates
green, and PUBLISHED.** Tag `v3.9.0` on the bump commit
`1f0c0f1cbddfc938ffc3ec9dc4a9fe261c5ce7cd`, GitHub release at
https://github.com/erkkimarkus/smaily-wordpress-plugin/releases/tag/v3.9.0,
ZIP SHA256 `121e5ab79a7a0ae2209e23c004cb93ce617f555c401ec88c69fbf398dfb14c1c`
(unchanged from the build below — confirms the published asset matches what
was gated). MINOR bump — Transactional emails v1
lands (PRO-1504, off by default), the F3-35 `Update URI` clobber-guard
removed ahead of the sendsmaily upstream merge. Version bumped in all four
places (`smaily-connect.php` header + `SMAILY_CONNECT_VERSION` +
`SMAILY_CONNECT_PLUGIN_VERSION`, `package.json`, `readme.txt` Stable
tag/Changelog/Upgrade Notice) plus the three test pins (`ConstantsTest.php`,
`tests/bootstrap.php`, `tests/phpstan-bootstrap.php`), committed first
(`1f0c0f1cbddfc938ffc3ec9dc4a9fe261c5ce7cd`, on `main`, pushed and tagged
`v3.9.0` by the orchestrator). Content since the v3.8.1 tip (`123d479`):
PRO-1504 Stages 1+2 (transactional order/shipping-confirmation emails via a
dedicated Smaily account, native-WC-email suppression, fail-open fallback),
PRO-1518 (order confirmation also fires on WooCommerce Blocks / Store-API
checkout), PRO-1519 (a 1-hour retry ceiling closes the fail-open gap on a
persistent transient failure), PRO-1506 (`catalog.delete` tombstone repair
now also runs at flush time, healing a pre-3.8.1 stuck row on Retry),
PRO-1517 (mock-only rec-engine GDPR-path fidelity fixes, test-only, no
version-bump-worthy change on its own), the F3-35 `Update URI` header
removal, and PRO-1537 (transactional `context` merge-tag fields —
`first_name`/`last_name`/`order_number`/`payment_method`/`shipping_method`/
`product_name`/`product_description` — now `htmlspecialchars()`-escaped
before reaching `message/send.php`, closing the Should-fix the v3.9.0 gate
delta security audit flagged). Five user-facing changelog items (see
`readme.txt` 3.9.0 entry): the new opt-in Transactional emails feature, the
Store-API checkout-confirmation fix, the catalog-removal retry-repair fix,
the retry-ceiling hardening, and the merge-tag escaping hardening.
**PRO-1537 live-probe outcome (run today, 2026-07-23, `bin/walk-
pro1537-escape-probe.cjs` against the smailydemo sandbox):** Smaily's own
`message/send.php` merge-tag substitution does **NOT** escape `context`
values itself — a raw `<b>probe-bold</b>` `first_name` rendered as live
unescaped HTML in the received email, and a pre-escaped `&lt;i&gt;…&lt;/i&gt;`
`shipping_method` literal displayed as plain `<i>…</i>` text (single decode
on display, confirming no double-escaping risk). This confirms the
plugin-side `TransactionalPayloadBuilder::escape()` fix (PRO-1537, already
landed) is the **only** escaping layer in the pipeline — not a double-escape
risk, and not optional. **Second finding, same probe run: the SUBJECT line
substitutes `context` merge tags exactly like the body.** With the workflow
subject set to the bare `{{subject}}` tag and `context.subject = "Probe-
subject <b>subj</b> & Co"`, the received email's subject was that literal
string verbatim — raw passthrough, no stripping or escaping. A sender can
therefore take over the entire subject line per-send via a `{{subject}}`
merge tag; HTML isn't stripped there either (harmless in a text header, but
user-controlled HTML/markup should stay out of it). This is a new
observation, not a code change — no plugin surface currently feeds a
merchant/customer-controlled value into a transactional subject. Builds:
`npm run build:admin && npm run build:client`
(`dist/admin/admin.js` 290.62 kB, `dist/public/js/sc-runtime.js` 6.47 kB);
blocks rebuilt (`composer run install-block-modules && composer run
build`); **i18n rebuild RUN** (`sg docker -c "bash bin/build-i18n.sh"`,
required this cut — the new Transactional-emails admin strings, e.g.
"Enable transactional emails" / "Counts as shipped", needed extraction into
`languages/smaily-connect.pot` + `-et.po` and the admin-bundle JSON
catalog `smaily-connect-et-464ceaab21588225a35cae9f83dfa47d.json`).
`composer install --no-dev --optimize-autoloader` → `composer run package`
→ `composer install` (dev vendor restored immediately after packaging,
verified via `vendor/bin/phpunit` present). **ZIP verified**: 3.9.0 in both
version strings present in the shipped tree (plugin header, readme Stable
tag — `package.json` is a dev source file, not shipped, by design);
required present (`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`,
`blocks/*/build/*` all three blocks, `vendor/autoload.php`,
`languages/smaily-connect-et.mo` + the admin-bundle et JSON,
`composer.json`); dev artifacts/tests/docs/node_modules/admin-src/dev-vendor
absent (grep-verified); **1 147 215 B** (vs. v3.8.1's 1 122 412 B — the
delta is consistent with the new transactional admin UI + backend code).
**PCP against the built ZIP** (`docker cp` into the wp-env `…-cli-1`
container, unzipped to `smaily-connect-pkg` — never the bind-mounted
`smaily-connect` dir — `--slug=smaily-connect --exclude-directories=vendor`,
temp copies cleaned up after): **`plugin_updater_detected` is GONE** (F3-35
removal confirmed working — the long-carried intentional finding is
retired), `mismatched_plugin_name` stays gone. Exactly **2 new WARNING
findings**, both `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` in
`includes/Smaily/TransactionalGate.php` (lines 66, 71 — `get_post_meta`/
`update_post_meta` calls on the new order-meta gate guard). Reported, not
fixed, per this task's scope (new transactional-code warnings are a
follow-up, not a release blocker — low-severity WPCS style warning, common
across WP plugins doing per-order meta reads). **`ci:strict` exit=0**
(PHPCS 0 errors [only pre-existing warnings], PHPStan clean, PHPUnit unit
**652/652** — was 650, +2 from PRO-1537's escaping tests; vitest 251/251,
tsc/eslint clean). **Integration suite RE-RUN in full**: `sg docker -c
"composer run test:integration"` **OK (180 tests, 907 assertions)** —
unchanged count from the PRO-1517 mock-fidelity pass (no new integration
tests landed since), dev sandbox tenant "Smaily Connect test" correctly
restored post-run (not MiuMjau, `connected=1`). **Published 2026-07-23**: tag `v3.9.0` on bump commit
`1f0c0f1cbddfc938ffc3ec9dc4a9fe261c5ce7cd`,
https://github.com/erkkimarkus/smaily-wordpress-plugin/releases/tag/v3.9.0,
ZIP SHA256 `121e5ab79a7a0ae2209e23c004cb93ce617f555c401ec88c69fbf398dfb14c1c`
(published by the orchestrator, as scoped to this pass at build time).

Prior: 2026-07-23 (**PRO-1537 — the v3.9.0 security audit's
Should-fix (Medium) fixed same-day.** `TransactionalPayloadBuilder`'s
`context` merge-tag fields — `first_name`, `last_name`, `order_number`,
`payment_method`, `shipping_method`, `product_name`, `product_description`
— now route through a new private `escape()` helper
(`htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 )`),
mirroring `CartPayloadBuilder::product_fields()`'s exact idiom byte-for-byte
(same flags). `product_sku`/`product_quantity`/prices left untouched, as
the audit recommended (SKUs are merchant-controlled, prices already go
through `price_display()`'s strip-tags treatment). **Tests:** +2 unit
(`TransactionalPayloadBuilderTest` — order-level fields with `<script>`/
`<b onmouseover=…>`/`&`/quote-bearing input assert the exact
`htmlspecialchars`-encoded output; product-line `product_name`/
`product_description` likewise). **Gates:** `npm run ci:strict` exit=0
(PHPCS 0 errors, PHPStan clean, PHPUnit unit 652/652 — was 650, +2; vitest
251/251 unchanged, tsc/eslint clean). Integration suite not run (unit-level
payload-builder change; the release worker runs full gates as part of the
v3.9.0 cut). Docs: `docs/audits/SECURITY_DELTA_2026-07-23.md` gets a
Resolution note on the finding (finding text itself left intact) +
`docs/audits/INDEX.md`'s v3.9.0 register row gets the same-day-fixed note.
**Not released** — code landed on `main`, rides the v3.9.0 cut the
orchestrator controls.)

Prior: 2026-07-23 (**v3.9.0 release-gate delta SECURITY audit
(PRO-1520 acceptance criterion 1) — 0 Blocker/Critical/High, 1 Should-fix
(Medium), recorded, not fixed (audit-and-record scope).** Delta
`123d479..69a83b8` (v3.8.1-publication tip to the pre-cut main tip; 5255/153
lines, 46 files — over the >2,000-line policy threshold, and independently a
release boundary): full read + adversarial pass on the new transactional-
email sender (PRO-1504 Stages 1+2), the PRO-1518 Store-API checkout twin,
the PRO-1519 retry ceiling, the PRO-1506 flush-time tombstone repair, the
PRO-1517 mock-only fixes, and the F3-35 `Update URI` removal. **Should-fix**:
`TransactionalPayloadBuilder`'s `context` merge-tag fields (`first_name`,
`last_name`, `order_number`, `payment_method`, `shipping_method`,
`product_name`, `product_description`) reach `message/send.php` without the
`htmlspecialchars()` treatment the sibling `CartPayloadBuilder` already
applies to the same-purpose fields for the same delivery mechanism —
`first_name`/`last_name` are attacker-controlled via WC checkout and the
recipient email is attacker-chosen too, so this is a plausible content-
injection path into a transactional email IF Smaily's own merge-tag
substitution doesn't already escape (external, unverified either way); not a
release blocker (default-off feature, narrow mechanical fix, no proven local
vulnerability) but flagged as a fast-follow before the feature reaches a
pilot store. **Confirmed clean**: transactional credential storage reuses
the existing `encrypt_password()`/`Credentials` path (zero new crypto,
password never in the boot payload); `Client::send_message()`/
`TransactionalFlusher` never capture the Authorization header (F3-44 rule
holds, directly tested); the send gate is genuinely default-off and every
new WC hook binding is internal/unauthenticated-unreachable; fail-open + the
once-per-order-per-type meta guard make a double-send structurally
impossible across sync+retry and across the PRO-1518 twin hooks; the
PRO-1519 ceiling is type-scoped and time-bounded; no new REST route (the
new `trigger_type` allowlist genuinely closes the gap named in the Stage 1
record); PRO-1506 reuses two pre-existing, already-audited
`CatalogPayloadBuilder` methods at a new call site; PRO-1517 touches only
`tests/`/`docs/`, nothing shipped; the `Update URI` removal is header-only
and correctly retires the long-carried intentional PCP
`plugin_updater_detected` finding (the v3.9.0 PCP run should come back
clean — a reappearance would be a regression signal, not the old baseline).
**PCP against the built ZIP explicitly deferred to the release-build worker
running the v3.9.0 cut later today** (out of scope for this pass). No
`ci:strict`/integration re-run in this pass (docs-only audit-and-record
task; STATUS.md already records green gates per code-touching commit in the
delta). Docs: `docs/audits/SECURITY_DELTA_2026-07-23.md` (new report),
`docs/audits/INDEX.md` new register row.)

Prior: 2026-07-23 (**Upstream-merge prep — the F3-35 `Update URI`
clobber-guard header removed from `smaily-connect.php`**, ahead of the
sendsmaily upstream merge (Erkki's direction). WP core never offers a
LOWER version than what's installed, so the wordpress.org listing (frozen
at 2.0.0) could never clobber this fork (3.8.1+) regardless of the header;
removing it now is the intended migration mechanic — stores transition
automatically onto the wordpress.org update channel the moment sendsmaily
publishes a release ≥ the installed version, with no separate cutover step
later. Docs updated in the same commit: DECISIONS.md F3-35 gets a
superseded note (the renumbering half of that decision stands; only the
header guard is retired); `docs/UPSTREAM_MERGE_PROPOSAL.md` checklist item
2 marked done, plus items 3 (React admin i18n) and 4 (inline-script →
enqueue) corrected from stale "deferred" to done (both shipped earlier,
W-7/W-5); CLAUDE.md's PCP note updated (`plugin_updater_detected` is no
longer an intentional finding). **Rides the v3.9.0 cut** a later worker
runs today (PRO-1520) — this change does not bump the version itself.
Gates: `npm run ci:strict` exit=0 (doc + header-only change, no test
behaviour affected; confirmed no test asserts on the `Update URI` header).
Integration suite not run for this change (release worker runs it as part
of the v3.9.0 cut).)

Prior: 2026-07-22 (**PRO-1517 — two mock rec-engine GDPR-path
fidelity gaps closed (cross-repo note, Shopify mock fix `cb262c4`).** The
integration mock (`tests/Integration/Fixtures/mock-rec-engine/router.php`)
simulated the real engine's own server-side GDPR/opt-out behaviour too
loosely on two paths — a plugin test exercising either would false-green:
(1) `POST /customer/{email}/opt-out` always returned 200, never the
contract's 404 for an unknown customer; (2) browse ingest (§6) didn't apply
the §10 Art 21 engine-side binding gate to a `smaily_visitor_token`-only
event (no `customer_email` on the event) — in fact the mock had **no**
opt-out state tracking at all before this (broader than the Shopify mock's
starting point, which already gated the email path). Fixed: the opt-out
route now persists an `opted_out_emails` registry and 404s a `notfound`-
prefixed email (same trigger convention + response shape as the sibling
§8/§9 routes); `identity_merge` now records `smaily_visitor_token →
customer_email`; browse ingest checks both the direct email and the
token-resolved email against the opt-out registry before counting an event
`with_customer_match` vs `anonymous`. `external_id` resolution stays
unmodeled (no registry — same limitation the Shopify reference carries).
**Tests:** +5 integration (`tests/Integration/RecEngineMockFidelityTest.php`,
new file, calls `Client` directly against the mock — this pins the mock's
simulated engine behaviour, not a plugin code path): unknown-customer 404,
known-customer opt-out success, a token bound via identity-merge to an
opted-out customer is forced anonymous, an unbound token still resolves as
identified, an email-carrying opted-out event is forced anonymous (this last
one is new *coverage*, not new *behaviour* pinning — see above, the email
path had no gate to pin before either). **Gates:** `npm run ci:strict`
exit=0 (PHPUnit unit 650/650 unchanged — no plugin/production code touched,
only the mock + integration tests; vitest 251/251 unchanged, tsc clean);
`sg docker -c "composer run test:integration"` OK (180 tests, 907
assertions — was 175, +5), sandbox tenant "Smaily Connect test" correctly
restored post-run. Docs: `docs/audits/MOCK_DIVERGENCE_AUDIT.md` gets a new
§5 registering + closing both gaps. **Not released** — mock/test-only
change, no plugin code or version bump; nothing to release.)

Prior: 2026-07-22 (**PRO-1519 — bounded retry ceiling closes the
fail-open gap on a persistent transient failure.** The recorded PRO-1504
fail-open decision assumed a queued transactional row eventually hits either
success or a TERMINAL Smaily rejection; it missed that a run of purely
TRANSIENT failures (broken credentials, a prolonged Smaily outage) never
produces a terminal response, so under the Smaily EventQueue's normal
unbounded-retry-until-manual-review convention the row (and the native WC
email `TransactionalSuppression` keeps suppressed the whole time) would
retry forever — the customer never gets ANY confirmation email. Fixed with a
TIME-based ceiling scoped to the two transactional event types only:
`TransactionalFlusher::RETRY_CEILING_SECONDS` = `HOUR_IN_SECONDS`, checked
against the row's `created_at` at the top of the retry path
(`enforce_retry_ceiling()`) — once exceeded it throws the SAME
`TerminalDispatchException` a deterministic Smaily rejection throws, so the
row flows through the EXISTING `mark_failed` + fail-open path with zero new
fallback logic. The synchronous first attempt (`send_now()`) is unaffected
(a fresh row's age is always 0); the main `Flusher` / `CartFlusher`
(marketing-side rows — `contact.sync`, `automation.*`, abandoned cart) are
untouched, no shared code path. **Tests:** +3 unit
(`TransactionalFlusherTest` — past-ceiling fails open without an API call;
still-within-ceiling keeps retrying normally; a non-transactional-type row
is never force-failed even when ancient) +2 integration
(`TransactionalEmailsPipelineTest` — a row stuck on repeated 5xx fails open
once backdated past the ceiling and the next AS tick runs, timestamp
manipulation not `sleep()`; a backdated `contact.sync` row proves the
ceiling doesn't leak into the shared marketing `Flusher`). **Gates:**
`npm run ci:strict` exit=0 (PHPUnit unit 650/650, was 647, +3; vitest
251/251 unchanged, tsc clean); `sg docker -c "composer run
test:integration"` OK (175 tests, 893 assertions — was 173, +2), sandbox
tenant "Smaily Connect test" correctly restored post-run. Docs:
`docs/DECISIONS.md` PRO-1504 Stage 2's "Alternatives considered" (which
rejected a retry ceiling) is marked superseded with a forward-pointer, and a
full PRO-1519 addendum records the reversal + why time-based, why one hour;
merchant docs site (`docs/site/index.html`, EN+ET) — the transactional-
emails section's fail-open sentence now names the ~1-hour bound so it no
longer implies an untimed guarantee. **Not released** — code landed on
`main`, no version bump; the release cut is the orchestrator's call.)

Prior: 2026-07-22 (**PRO-1518 — order-confirmation now also fires on
WooCommerce Blocks / Store-API checkout.** PRO-1504 Stage 2 (below) wired
order confirmation to `woocommerce_checkout_order_processed` only — that hook
NEVER fires for a Store-API/block checkout (WC default since 8.3), so a
block-checkout store got no order-confirmation send at all. Fixed by mirroring
the exact F3-46 precedent (`HookHandler::on_block_checkout_order_processed`):
`TransactionalEmailHookHandler::on_block_checkout_order_processed( \WC_Order
$order )` bound to `woocommerce_store_api_checkout_order_processed` (1-arg
Store-API shape, unlike the classic 3-arg hook) in `Bootstrap::init_hooks()`
right after the classic binding. No new logic — both hooks call the same
`attempt()`, which already once-per-order-per-type meta-guards on
`_smly_plus_transactional_order_confirmation_status`, so a store that somehow
fired both hooks for one order still sends exactly once. Classic-checkout
behaviour is untouched. **Tests:** +2 unit
(`TransactionalEmailHookHandlerTest` — Store-API hook calls the flusher when
the gate is open; idempotence across both hooks, with a flusher double that
now stamps the meta guard like the real `TransactionalFlusher::send_now()`
does, so the guard is actually exercised) +2 integration
(`TransactionalEmailsPipelineTest` — the Store-API hook sends once end-to-end
against the mocked `message/send.php`; firing both hooks for the same order
still sends exactly once). **Gates:** `npm run ci:strict` exit=0 (PHPUnit unit
647/647, was 645, +2; vitest 251/251 unchanged, tsc clean); `sg docker -c
"composer run test:integration"` OK (173 tests, 883 assertions — was 171, +2),
sandbox tenant "Smaily Connect test" correctly restored post-run. Docs:
`docs/DECISIONS.md` PRO-1504 Stage 2 entry extended with a short addendum;
merchant docs site unchanged (its Transactional-emails section never claimed
classic-only, so nothing there was stale). **Not released** — code landed on
`main`, no version bump; the release cut is the orchestrator's call.)

Prior: 2026-07-22 (**PRO-1504 Stage 2 — Transactional emails: the
sender, native-email suppression, and fail-open fallback landed.** Builds on
Stage 1's config surface (below) exactly per Erkki's 2026-07-22 design
approval — no redesign. What shipped:
(1) **`Smaily\Client::send_message()`** — a new `POST /api/message/send.php`
call on the transactional account's subdomain (JSON body `{autoresponder_id,
to, context}`, unlike every other Client method's form encoding; a new
`$json` flag on the private `request()` helper carries this). Success = HTTP
200 + body `{code:101}`; ANY other body code (203 validation, 221 invalid
autoresponder, or unlisted) is TERMINAL; network/5xx/429 throw `ApiException`
(transient), matching the existing Smaily response-codes convention.
(2) **`TransactionalGate`** — the single source of truth for "is a
transactional send allowed right now" (all four: master toggle, per-trigger
toggle, a mapping row resolves, the mapped account's credentials are
complete), shared verbatim by the WC hook handler AND the native-email
suppression filters so they can't drift apart. Deliberately NO consent/
opt-out check (platform answer Q7, PRO-1380 — transactional overrides
marketing opt-out).
(3) **`TransactionalPayloadBuilder`** — builds the `context` merge-tag object
from a WC_Order: order-level fields (order_number, order_total — already
GROSS via `get_total()`, currency, payment_method, shipping_method, first/
last name) + the SAME `product_<field>_1..10` + `over_10_products`
template-parity matrix CartPayloadBuilder established, sourced from the
frozen order-item snapshot (survives a since-deleted product) with gross
per-unit pricing (PRO-1241: `get_total()+get_total_tax()` basis, not the
product's live price).
(4) **`TransactionalFlusher`** — one dispatcher for BOTH the synchronous
first attempt (`send_now()`, called inline from the WC hook) and the queued
retry (`flush()`, its own AS action `smly_plus_flush_transactional_events`,
`transactional.order_confirmation` / `transactional.shipping_confirmation`
event types on the shared Smaily `EventQueue`). `send_now()` ALWAYS enqueues
the row first — even on an immediate synchronous success — so every attempt
lands in the Event Log (F3-44 exchange capture), not just failures. The main
`Flusher` and `CartFlusher` both exclude the two new event types (event-type
scoping, same discipline as the rec-engine flushers) — `Flusher::flush()`'s
exclude list now carries three entries.
(5) **`TransactionalEmailHookHandler`** — `woocommerce_checkout_order_
processed` (order_confirmation, one-shot, deliberately NOT
`woocommerce_thank_you`) and `woocommerce_order_status_changed` (shipping_
confirmation, fires when the bare-slug new status is in `smly_plus_shipped_
order_statuses`). Both are gated by an order-meta guard
(`_smly_plus_transactional_{type}_status`, values `queued`/`sent`/
`failed_open`) checked BEFORE calling the gate — once-per-order-per-type,
survives repeated status flips into the shipped set.
(6) **`TransactionalSuppression`** — `woocommerce_email_enabled_customer_
processing_order` / `_customer_completed_order` filters force `false` ONLY
while `TransactionalGate::resolve_if_open()` holds for that trigger (never
touches admin emails); completed-order suppression additionally requires
`'completed'` to be one of the merchant's chosen shipped statuses (a custom
status like "Shipped" has no native email to suppress). A request-scoped
static bypass flag lets fail-open re-fire the very email this class
suppresses without re-triggering its own filter.
(7) **Fail-open** (Erkki decision 2026-07-22): a TERMINAL failure — on the
sync attempt or a later queued retry — re-fires the corresponding native WC
email (bypassing suppression for that one call) and is itself guarded by the
SAME order-meta value (`failed_open`) so a manually-retried failed row can't
double-fire it; when no native email was ever suppressed for that trigger
(the custom-shipped-status case), fail-open just leaves the `mark_failed`
row as the record.
**Tests:** +40 unit across 6 files (`ClientTest` +3 for `send_message()`
JSON body/auth/5xx; `TransactionalPayloadBuilderTest` — order-level fields,
gross pricing, deleted-product-line survival, the 10-slot matrix + overflow;
`TransactionalGateTest` — all four gate conditions independently, resolver
queried with `language=null`; `TransactionalSuppressionTest` — suppress-
only-while-open, completed-order's extra shipped-status condition, the
bypass mechanic; `TransactionalFlusherTest` — success/terminal/transient/
fail-open/meta-guard/queue-scoping; `TransactionalEmailHookHandlerTest` —
gate-closed no-op, meta guard, shipped-status membership + `wc-` prefix
normalisation, repeated-flip no-resend) +1 fixed pre-existing test
(`FlusherTest`'s exclude-list assertion now expects three event types).
+6 integration (`TransactionalEmailsPipelineTest`, new file) against a real
wp-env WC order + the Smaily API mocked at `pre_http_request` (the
established CartPipelineTest pattern — NOT the rec-engine mock, a different
API): order confirmation sends once with the mapped workflow + product
matrix; shipping confirmation sends once and a flip-away-then-back doesn't
resend; native processing-order suppression toggles live with the master
switch; everything-off is a verified zero-behavior-change no-op; a terminal
203 marks the queue row failed + sets the fail-open meta (the actual WC
mailer re-fire logic is unit-covered per the task's own allowance; this run
DID observe the container attempt a real `sendmail` call and fail
harmlessly — confirms the wiring reaches the real WC trigger); a 5xx lands
the row `pending`, the main flusher's own hook leaves it untouched, and
`TransactionalFlusher`'s dedicated hook drains it to `sent`.
**Gates:** `npm run ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit
unit 645/645 — was 605, +40 new across the six files above, plus one
pre-existing `FlusherTest` assertion updated for the wider exclude-list;
vitest 251/251 unchanged, tsc clean); `sg docker -c "composer run
test:integration"` OK (171 tests, 875 assertions — was 165, +6 new), sandbox tenant "Smaily
Connect test" correctly restored post-run (not MiuMjau). Docs:
`docs/DECISIONS.md` PRO-1504 Stage 2 entry; merchant docs site
(`docs/site/index.html`) new "Transactional emails" section in BOTH
languages (Settings tab TOC + body), covering the account/toggles, the two
triggers, the suppression behaviour, the fail-open fallback, and the two
merchant-facing template caveats (single-section workflow; subject line
must be a merge tag). **Not released** — code landed on `main`, no version
bump; the release cut is the orchestrator's call.)

Prior: 2026-07-22 (**PRO-1504 Stage 1 — Transactional emails:
settings + mapping UI landed, NO send path.** Option B (a separate Smaily
account bound purely for transactional sends, isolated from marketing
deliverability — approved by Erkki 2026-07-22) built as pure configuration:
(1) a second Smaily account under `Settings\Credentials` account_key
`'transactional'` (reuses the existing multi-account mechanism — no new
storage class), behind an enablement toggle (`smly_plus_transactional_emails_
enabled`, default OFF); (2) two new automation-mapping trigger types,
`order_confirmation` + `shipping_confirmation`, stored as ordinary rows in
the EXISTING `smly_plus_automation_mapping` table (no schema change) —
`AutomationSection` gained one prop (`accountKeyOverride`) so the mapping row
pins to the transactional account instead of the site's multilingual mode;
(3) a "counts as shipped" order-status multi-select (`smly_plus_shipped_
order_statuses`, default `['completed']`), choices from `wc_get_order_
statuses()` via a new `EnvDetector::order_statuses()` env field; (4)
`SettingsEndpoint::replace_automation_mappings()` gained an explicit
trigger_type allowlist (previously any string reached an INSERT). With the
toggle off (default) nothing new renders and no customer-facing behavior
differs — deliberately NO send path, NO WC-email suppression, NO order/
shipment hook binding this stage; that's a later, separately-approved stage.
**Tests:** +7 unit (`EnvDetectorTest` — orderStatuses snapshot + bare-slug
stripping + transactional saved-settings defaults/read-back; `SettingsEndpointTest`
— transactional credential persistence/verified-flag, new-toggle persistence,
trigger_type allowlist accept/reject) +1 integration (`SettingsRoundTripTest`
— writer/reader key symmetry for the whole transactional slice) +3 vitest
component (`TransactionalEmailsSection.test.tsx` — off renders nothing beyond
the toggle and fires no `/workflows` call; on reveals the credential block +
both trigger sections whose dropdowns fetch the `'transactional'` account_key,
never `'default'`; shipped-status checkboxes read/toggle `env.orderStatuses`).
**Gates:** `npm run ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit
unit 605/605, vitest 251/251, tsc clean); `sg docker -c "composer run
test:integration"` OK (165 tests, 842 assertions), sandbox tenant "Smaily
Connect test" correctly restored post-run (not MiuMjau). Docs:
`docs/DECISIONS.md` new PRO-1504 entry (design + stage split rationale).
Merchant docs site (`docs/site/index.html`) update deliberately DEFERRED to
the stage that actually ships/releases this (orchestrator decision — stage 1
alone is never released, so there's no user-visible behavior yet to
document). **Not released** — code landed on `main`, no version bump; stage 2
(the sender, native-email suppression, fail-open fallback) is a separate
future task/decision.)

Prior: 2026-07-22 (**PRO-1506 — `catalog.delete` force-fill now ALSO
runs at FLUSH time, so a pre-3.8.1 stuck row heals on Retry.** MiuMjau update
+ a Retry of the 52 stuck `catalog.delete` rows (the v3.8.1 entry's assumed
"last checkbox") FAILED AGAIN with the identical errors (51× empty
`product_url`, 1× empty `category_path`) — PRO-1498's
`ensure_valid_removal()`/`build_unresolvable()` ran only at ENQUEUE time
(`CatalogHookHandler`/`CatalogBackfillJob`); `IngestFlusher::row_to_object()`
sent a `catalog.delete` row's STORED captured object verbatim, so a row
captured BEFORE the PRO-1498 fix just resent the same stored blank forever —
the enqueue-time fix cannot retroactively heal a row already sitting in the
queue. **Fix:** the flusher's `catalog.delete` branch now also calls
`ensure_valid_removal()` on the stored object before send (idempotent — a
no-op on an already-valid post-3.8.1 capture, so enqueue-time behaviour and
the flag semantics are unchanged), and falls back to
`build_unresolvable( entity_id, event_uuid )` when the row carries no
captured object at all (corrupt/missing payload) instead of a terminal skip
with nothing POSTed. Single chokepoint, no new logic — reuses the two
PRO-1498 builder methods from a second call site. **Test consequence:**
`RecEngineCatalogTest::test_mock_rejects_empty_product_url_on_a_delete_row_
like_the_live_engine` used to prove mock/live parity by enqueueing-then-
flushing a blank-`product_url` delete row and asserting the engine rejected
it — that path can no longer reach the mock with a blank value now that the
flusher repairs it first, so the test now posts the raw blank payload
directly through `Client::ingest_catalog()` to keep the same parity proof.
**Tests:** +2 unit (`IngestFlusherTest` — a stored-blank row is repaired and
sent, not skipped/failed; a row with no captured object falls back to
`build_unresolvable()` and is sent, not terminal-skipped) +1 integration
(`RecEngineCatalogTest` — a directly-enqueued row simulating the pre-3.8.1
stored-blank shape drains successfully on flush, `tags.category_defaulted`
reaches the engine) +1 rewritten integration (the mock-parity test above).
**Gates:** `npm run ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit
unit 597/597, vitest 248/248); `sg docker -c "composer run test:integration"`
OK (164 tests, 830 assertions), sandbox tenant "Smaily Connect test"
correctly restored post-run (not MiuMjau). Docs: `docs/DECISIONS.md` new
PRO-1506 entry, `CLAUDE.md`'s PRO-1498 section extended with the flush-time
addendum. **Not yet released** — code landed on `main`; MiuMjau's 52 rows
still need one more live Retry once this ships in a release (the fix makes
the NEXT retry succeed, it doesn't resend anything by itself).)

Prior: 2026-07-21 (**v3.8.1 — RELEASED** (gates green; built/gated by
the worker pass, published by the orchestrator the same session — see the
publication note at the end of this entry). PATCH bump (fixes + hardening, no new
merchant-facing functionality). Version bumped in all four places
(`smaily-connect.php` header + `SMAILY_CONNECT_VERSION` +
`SMAILY_CONNECT_PLUGIN_VERSION`, `package.json`, `readme.txt` Stable
tag/Changelog/Upgrade Notice) plus the three test pins (`ConstantsTest.php`,
`tests/bootstrap.php`, `tests/phpstan-bootstrap.php`), committed first
(`251a29f`, pushed to `main`). Content since the v3.8.0 release record
(`9cd41be`): PRO-1498 catalog tombstone force-fill
(`5fe4811`/`296377c`/`33a2f35`/`c816d89` — a catalog.delete row for a
deleted/unresolvable product is now always sent instead of being silently
skipped when its captured category_path/product_url come back blank, or the
product no longer resolves to a `WC_Product` at all), PRO-1486 (`3a47eb3` —
the `/relay` browse proxy no longer accepts a client-supplied
`customer_email`, closing the spoofable whitelist entry the v3.8.0 delta
audit flagged as Info), PRO-1499 (`1303abc`/`0910b06`/`45cf1f3`/`8c5e5b6` —
contract synced to v1.6.0, `tags.category_defaulted` implemented +
live-walked). Three user-facing changelog items: removal of a deleted
product from recommendations is now reliable even when its underlying
WooCommerce data is gone; products rescued under the store's default
category (3.8.0) are now marked so recommendations categorize them
correctly; the browse-tracking endpoint hardened so identity comes only
from the shopper's real login, never a browser-supplied value.
`npm run build:admin && npm run build:client`; blocks rebuilt (`composer run
install-block-modules && composer run build`); i18n rebuild SKIPPED
(confirmed via `git log aea32bb..HEAD -- admin/src languages/` — zero hits).
`composer install --no-dev --optimize-autoloader` → `composer run package` →
`composer install` (dev restored). ZIP verified: v3.8.1 in all three version
strings, required present (`dist/admin/admin.js`, `dist/public/js/
sc-runtime.js`, `blocks/*/build/*`, `vendor/autoload.php`,
`languages/smaily-connect-et.mo` + admin-bundle et JSON, `composer.json`),
dev artifacts/tests/docs/node_modules/admin-src absent, **1 122 412 B** (vs.
v3.8.0's 1 119 339 B). **PCP against the built ZIP** (unzipped to
`smaily-connect-pkg` in the wp-env `…-cli-1` container, never the
bind-mounted `smaily-connect` dir; `--slug=smaily-connect`): exactly the
expected `plugin_updater_detected` ERROR (F3-35), nothing else — no
`upgrade_notice_limit` this time (267 chars, under the 300 limit).
Container temp copies cleaned up afterward. **`ci:strict` exit=0** (PHPCS 0
errors, PHPStan clean, PHPUnit unit 595/595, vitest 248/248, tsc/eslint
clean). **Integration suite RE-RUN in full**: `sg docker -c "composer run
test:integration"` OK (163 tests, 826 assertions), dev sandbox tenant
"Smaily Connect test" correctly restored post-run (not MiuMjau). **Delta
security audit**: full re-audit at the release boundary (delta
`9cd41be..251a29f`) — adversarial pass on the PRO-1486 `/relay` strip (the
pure-narrowing verification the same-day register note deferred to this
pass: confirmed no bypass via case/nesting/type confusion, injection
ordering unchanged) plus a new-input-surface check on the PRO-1498/1499
catalog commits (none found — internal WP post ids + already-built catalog
objects only, no new route/external HTTP/SQL). Verdict PASS, 0 findings.
Full report `docs/audits/2026-07-21-SECURITY_DELTA_AUDIT_V381_GATE.md` +
register row in `docs/audits/INDEX.md`. **Published (orchestrator step, same
session):** `gh release create v3.8.1` on `erkkimarkus/smaily-wordpress-plugin`
— asset verified byte-identical (1 122 412 B), Latest, non-prerelease, tag on
the bump commit `251a29f`. Post-release: MiuMjau update → final Retry of the
52 stuck `catalog.delete` rows (PRO-1498's last checkbox).

Prior: 2026-07-21 (**PRO-1499 — contract synced to v1.6.0 (engine
commit `06266a8`); `tags.category_defaulted` implemented + live-walked.**
`docs/RECENGINE_API_CONTRACT.md` byte-identically synced from the engine's
main (`bin/check-contract-staleness.sh` confirms in-sync). Two additions: (1)
optional catalog tag `tags.category_defaulted` (`"true"`, omit-on-false) —
marks a row whose `category_path` is a sender-substituted placeholder (the
PRO-1491 store-default-category fallback, or a PRO-1498 delete-tombstone
force-fill) rather than real taxonomy, so the engine skips category-slug-keyed
derivation for it (engine-side skip already deployed, PRO-1500); (2) a
wording-only §6 deprecation notice for client-originated `customer_email` on
browse events — verified already fully covered by the same-day PRO-1486 strip
(`BeaconEndpoint::EVENT_FIELDS`), no further code change needed. **Plugin
follow-through (CC-8):** `CatalogPayloadBuilder::primary_category_path()`
gained an optional by-ref `$defaulted` out-param; `build()`'s `tags()` stamps
the flag only when a real (non-empty) placeholder value was actually
substituted (an unresolvable-default empty `category_path` stays unflagged —
that row fails the engine's REQUIRED-field check regardless, nothing to
flag); `ensure_valid_removal()` stamps it exactly when it force-fills a still-
blank `category_path`; `build_unresolvable()` always carries it (every field
on that tombstone is definitionally a placeholder). **Mock:** no change
needed — `tests/Integration/Fixtures/mock-rec-engine/router.php` already
captures the whole `tags` object per-SKU with no keys allowlist (PRO-1224
precedent). **Tests:** +unit assertions across the existing
`CatalogPayloadBuilderTest` fallback/removal/tombstone cases (flag present on
substituted rows, absent everywhere else — including the existing real-
category test's unchanged exact-array assertion proving omission); +assertions
in `RecEngineCatalogTest`'s PRO-1491 no-term-product upsert test and PRO-1498
force-filled-removal test, both now also checking the flag reached the wire
via `last_catalog_tags` mock introspection. **Live-walk:**
`bin/walk-pro1499-category-defaulted.cjs` (new) proves the real sandbox engine
("Smaily Connect test" tenant, confirmed not MiuMjau) accepts a no-
product_cat-term product's catalog.upsert carrying
`tags.category_defaulted:"true"` — `{"processed":1,"sent":1,"failed":0}`,
`{"http":200,"outcome":"accepted"}`. **Gates:** `npm run ci:strict` exit=0
(PHPUnit unit 595/595, vitest 248/248); `sg docker -c "composer run
test:integration"` OK (163 tests, 826 assertions — 3 more assertions than the
PRO-1486 baseline, no new test methods, only added assertions within existing
tests), sandbox tenant "Smaily Connect test" correctly restored post-run (not
MiuMjau).)

Prior: 2026-07-21 (**PRO-1486 — the `/relay` browse proxy no longer
accepts a client-supplied `customer_email`.** Linear decision recorded
2026-07-21, engine-confirmed via PRO-1490: `BeaconEndpoint::EVENT_FIELDS`
previously whitelisted `customer_email` straight through from the client POST
body with no origin check — spoofable (attach an arbitrary email to anonymous
browsing; probe another contact's profiling opt-out state by guessing
emails). No legitimate producer sends it client-side (F3-49; the JS
`enrich()` never has), and the contract explicitly supports senders omitting
identity hints, so nothing engine-side breaks. **Fix:** `customer_email`
removed from `EVENT_FIELDS` — a client-supplied value is now silently
whitelist-dropped before `attach_logged_in_identity()` (PRO-1389, the ONLY
remaining source, unchanged) or `filter_by_profiling()` ever run, so
server-side injection semantics are identical to before. **Scope caveat**
(engine team, honored in code + docs): the strip is specific to the
browse-event POST shape `/relay` handles today; a future storefront-
recommendations GET proxy that legitimately takes a `customer_email` query
param must not inherit `EVENT_FIELDS`/`validate_batch()` unmodified — flagged
in the `EVENT_FIELDS` docblock + class docblock + `docs/DECISIONS.md`
(PRO-1486 entry). **Dead-code cleanup:** `filter_by_profiling()`'s per-event
re-check for a client-supplied email DIFFERING from the server-resolved one
is removed (unreachable once the client-supplied value is stripped
upstream); the loop/counter/log structure is kept as defense-in-depth against
a hypothetical future `customer_email` producer, though it can't actually
trigger a drop in the current single-producer graph.
`attach_logged_in_identity()`'s now-unused `verified_email` return key is also
dropped. **Follow-up, not fixed here (recorded in DECISIONS + FOLLOW-UPS):**
`smaily_rec_id`/`smaily_ctx` remain in `EVENT_FIELDS` and are
client-suppliable via the same whitelist-pass-through mechanism, contrary to
F3-49's client-side-omission intent — the JS client never sends them today,
but the whitelist doesn't enforce that; a follow-up issue should evaluate
closing it the same way. **Tests:** +1 unit
(`BeaconEndpointTest::test_client_supplied_customer_email_is_stripped`), 1
unit test rewritten
(`BeaconEndpointIdentityTest::test_client_supplied_customer_email_is_stripped_and_never_checked`,
replacing the now-impossible "differing client-supplied email" scenario), 1
integration test rewritten
(`RecEngineBrowseProxyTest::test_client_supplied_customer_email_is_stripped_and_not_used_for_profiling`,
replacing the two old client-supplied-email profiling-opt-out tests — the
real opt-out path stays covered by the existing PRO-1389 logged-in-cookie
tests). **Security audit:** a register note was added to
`docs/audits/INDEX.md` for the narrowed `/relay` surface — a full re-audit
was judged not required for a narrowing (input-rejecting) change. **Gates:**
`npm run ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit unit
595/595, vitest 248/248, tsc/eslint clean); `sg docker -c "composer run
test:integration"` OK (163 tests, 823 assertions — one net fewer test than
the v3.8.0 baseline: two old client-supplied-email profiling tests replaced
by one new strip-proof test), sandbox tenant "Smaily Connect test" correctly
restored post-run (not MiuMjau).)

Prior: 2026-07-21 (**PRO-1498 — `catalog.delete` tombstones are now
ALWAYS force-filled and sent, never silently skipped.** MiuMjau live evidence:
51 `catalog.delete` rows failing engine validation with empty `product_url`,
+1 with empty `category_path` — a synced-then-removed product left stuck
`in_stock=true` forever (the engine has no delete-by-key; F3-39/F3-40's
original skip-on-blank-field guard was correct for a never-published
auto-draft but wrong for an already-synced product). Fix:
`CatalogPayloadBuilder::ensure_valid_removal()` force-fills a still-blank
`category_path`/`product_url` with a generic placeholder
(`'uncategorized'` / a synthetic `home_url('/?smaily_connect_removed_product=
{id}')` URL) instead of leaving it blank; `CatalogPayloadBuilder::
build_unresolvable()` builds a whole minimal tombstone from the bare id when
`wc_get_product()` fails completely (e.g. a since-deactivated gift-card
plugin's `product_type`) — wired into `CatalogHookHandler::
enqueue_delete_unresolvable()` and `CatalogBackfillJob::
enqueue_unavailable_unresolvable()`. `CatalogHookHandler::is_removable()` (the
old skip-gate) is retired. New `SkuResolver::resolve_id()` canonicalizes a bare
id (mirrors the F3-43 `woo-oi-{item_id}` order-item fallback). Delete-only,
deliberately — the live `catalog.upsert` path keeps failing loud on the same
gap (F3-39's original intent, unchanged); §3b `catalog.remove` (PRO-1230, a
hard-deleted PARENT) is a different mechanism, untouched. **Mock strictness
(folds in PRO-1492):** `tests/Integration/Fixtures/mock-rec-engine/router.php`
now rejects an empty `product_url` the same way it already rejected
`category_path` (PRO-1491/e98e092) — `docs/audits/MOCK_DIVERGENCE_AUDIT.md`
updated. Full DECISIONS.md entry (PRO-1498). **Tests:** +9 unit (2
`CatalogPayloadBuilderTest` on `build_unresolvable`, 3 on `ensure_valid_removal`,
2 rewritten `CatalogHookHandlerTest` fallback assertions + 2 new
unresolvable-product cases, 2 rewritten + 2 new `CatalogBackfillJobTest`
cases) + 2 integration (`RecEngineCatalogTest::
test_uncategorized_product_removal_is_force_filled_and_sent_not_dropped` —
real trash hook → mock engine round trip, asserts `sent=1, failed=0` for a
genuinely category-less/unresolvable-default product, mirroring the existing
upsert-side fail-loud test's fixture; and
`test_mock_rejects_empty_product_url_on_a_delete_row_like_the_live_engine` —
proves the mock's new product_url check independent of whether the plugin's
own fallback ever actually produces such a row). The deeper
"`wc_get_product()` returns null entirely" case stays unit-tested only
(mirrors the project's existing "fragile to reproduce live" judgment for the
category-less-trashed-product edge) — reproducing it live would need
corrupting a real product's WC classification, which isn't a reliable
integration fixture. **Gates:** `npm run ci:strict` exit=0 (PHPCS 0 errors,
PHPStan clean, PHPUnit unit 594/594, vitest 248/248, tsc/eslint clean);
`sg docker -c "composer run test:integration"` OK (164 tests, 822
assertions), sandbox tenant "Smaily Connect test" correctly restored post-run
(not MiuMjau). **Not released as a
version bump in this pass** — landed as plain commits on `main`, to be
bundled into a future release like the rest of the PRO-1491 catalog work was.
**Post-release follow-up (not done here):** MiuMjau's existing 52 stuck rows
were captured under the OLD blank shape before this fix shipped — the code
fix does not retroactively repair them; they need a re-drive (re-touch the
affected products, or a targeted re-backfill) once a release carrying this
fix reaches the pilot.

Prior: 2026-07-21 (**v3.8.0 — RELEASED** (gates green; built/gated by
the worker pass, published by the orchestrator the same session — see the
publication note at the end of this entry). Version bumped in all four places
(`smaily-connect.php` header + `SMAILY_CONNECT_VERSION` +
`SMAILY_CONNECT_PLUGIN_VERSION`, `package.json`, `readme.txt` Stable
tag/Changelog/Upgrade Notice) plus the three test pins (`ConstantsTest.php`,
`tests/bootstrap.php`, `tests/phpstan-bootstrap.php`), committed first
(`aea32bb`, pushed to `main`). Content since the v3.7.2 release record
(`bc7bcc9`): the PRO-1389 ongoing-session identity injection on `/relay`
(`90e2712`/`34d8c3d`/`895c7da`) and its consent-lookup dedup follow-up, a
PRO-1402 decision record, a docs refresh (`ef1b911`, PRO-1197), and the
PRO-1491 catalog fixes (default-category fallback for no-term published
products + auto-draft-skip, `e98e092`/`642eae3`/`7d3c799`/`07e4f0c`/
`04789b8`) — a MINOR bump (new functionality, not just a fix). Three
user-facing changelog items: logged-in shoppers' browsing is now linked to
their account for the whole session (resolved server-side, email never
exposed to the browser, opt-outs respected); products with no category now
sync under the store's own default category instead of being skipped; empty
"Add new product" auto-draft placeholders no longer enqueue doomed sync rows.
`npm run build:admin && npm run build:client` (client entry `dist/public/js/
sc-runtime.js`); blocks rebuilt (`composer run install-block-modules &&
composer run build`); i18n rebuild SKIPPED (confirmed via `git log
bc7bcc9..HEAD -- admin/src languages/` — zero hits; on-disk `.mo`/admin-bundle
`-et-…json` unchanged from the 3.7.2-era build). `composer install --no-dev
--optimize-autoloader` → `composer run package` → `composer install` (dev
restored). **Gate-time PCP finding, fixed same session:** the first ZIP's
`readme.txt` 3.8.0 Upgrade Notice was 368 chars, over PCP's 300-char limit
(`upgrade_notice_limit` WARNING) — same class of fix as the v3.6.0 gate;
tightened to 290 chars, content unchanged (`9cd41be`), ZIP rebuilt. Final ZIP
verified: v3.8.0 in all three version strings, required present
(`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`, `blocks/*/build/*`,
`vendor/autoload.php`, `languages/smaily-connect-et.mo` + admin-bundle et
JSON, `composer.json`), dev artifacts/tests/docs/node_modules/admin-src
absent, **1 119 339 B** (vs. v3.7.2's 1 116 466 B — a ~2.7 KB increase in
line with the delta's new identity-injection + catalog-fallback code).
**PCP against the built ZIP** (unzipped to `smaily-connect-pkg` in the
wp-env `…-cli-1` container, never the bind-mounted `smaily-connect` dir;
`--slug=smaily-connect`): re-run after the upgrade-notice fix, exactly the
expected set — only the intentional `plugin_updater_detected` ERROR
(F3-35), nothing else. Container temp copies cleaned up afterward.
**`ci:strict` exit=0** (PHPCS 0 errors, PHPStan clean, PHPUnit unit
585/585, vitest 248/248, tsc/eslint clean). **Integration suite RE-RUN in
full**: `sg docker -c "composer run test:integration"` OK (162 tests, 814
assertions), dev sandbox tenant "Smaily Connect test" correctly restored
post-run (not MiuMjau). **Delta security audit**: the three PRO-1389
identity-injection commits (the auth/consent surface) were adversarially
reviewed against the release brief's explicit questions — injection gates
exclusively on WordPress core's own `wp_validate_auth_cookie()` (a forged or
expired cookie resolves to anonymous, never an attached email), the
opted-out path forwards events byte-for-byte unmodified (never dropped), the
resolved email is absent from every response/error path, and the
consent-lookup dedup narrows rather than widens the pre-existing (and
out-of-scope for this delta) `may_profile()` timing surface; PRO-1491's
catalog commits confirmed to carry no new user-input surface. Verdict PASS,
0 findings. Full report
`docs/audits/2026-07-21-SECURITY_DELTA_AUDIT_V380_GATE.md` + register row
in `docs/audits/INDEX.md`. **Published (orchestrator step, same session):**
`gh release create v3.8.0` on `erkkimarkus/smaily-wordpress-plugin` — asset
verified byte-identical (1 119 339 B), Latest, non-prerelease, tag on the
bump commit `aea32bb` per the release-tag convention. Post-release steps
tracked on the issues: MiuMjau update → PRO-1491 Retry of the old failed
catalog rows + PRO-1389 live identity verification; PRO-1481 identified-
session re-measurement window runs on post-v3.7.2 traffic.

Prior: 2026-07-21 (**PRO-1491 continuation — F3-39 REVISION (approved
by Erkki): published no-term products now get the store's own default
category name; auto-draft saves no longer enqueue catalog rows.** Two fixes
on top of the same-day root-cause investigation below (which stands
unchanged as the evidence trail). **Fix A** —
`CatalogPayloadBuilder::primary_category_path()` now falls back, when a
PUBLISHED product has zero `product_cat` terms, to the store's OWN
`default_product_cat` option: resolves that term and uses its actual NAME at
build time (never a hardcoded English literal — a localized/renamed store
gets its own term name). This is WooCommerce's own "uncategorized"
semantics, not an invented bucket, so it doesn't reverse the "connector
never makes a business-model call" principle — it forwards a value the store
itself already designates. If even the default term is unresolvable (a
genuinely broken store), the fail-loud `''` → engine-rejects behavior is
UNCHANGED — this only narrows the empirically-confirmed real case (the
MiuMjau 253 rows), it doesn't remove the guard. **Fix B** — a second,
independent root cause found during the same investigation:
`CatalogHookHandler::on_save_product()` now skips `auto-draft` status posts
(mirroring the existing `trash` early-return) — opening the WordPress "Add
product" screen creates an auto-draft placeholder (empty name/category/
price) that fires `save_post` before the merchant enters anything, which was
enqueuing a doomed catalog row every time. Plain `draft` is unchanged
(out of scope; see FOLLOW-UPS). The mock's strict empty-`category_path`
rejection (same-day earlier fix, unchanged) now guards the narrower
unresolvable-default edge instead of every no-term product.
**Tests**: unit — `CatalogPayloadBuilderTest` gained
`test_category_path_falls_back_to_store_default_category_when_product_has_
no_categories` (asserts the resolved term NAME, not "uncategorized") and
renamed the old no-fallback test to
`test_category_path_is_empty_string_when_default_category_is_unresolvable`
(same assertion, narrower scope); `CatalogHookHandlerTest` gained
`test_save_of_auto_draft_is_skipped`. Integration —
`RecEngineCatalogTest::test_uncategorized_product_upsert_is_rejected_by_the_
engine_and_marked_failed` renamed to
`test_uncategorized_product_upsert_uses_store_default_category_and_is_sent`
(now asserts `sent=1/failed=0` with `category_path==='Uncategorized'`, the
wp-env test site's real default term name) plus a new
`test_uncategorized_product_upsert_is_rejected_when_store_default_category_
is_unresolvable` (temporarily corrupts `default_product_cat` via
`update_option`/restores in a `finally`, proving the fail-loud edge still
works end-to-end against the mock). Gates: `npm run ci:strict` exit=0
(PHPCS 0 errors, PHPStan clean, PHPUnit unit `585/585` — was 583, +2; vitest
`248/248` unchanged). Integration:
`sg docker -c "composer run test:integration"` `OK (162 tests, 814
assertions)` — was 161/809, +1 test; dev sandbox tenant "Smaily Connect
test" correctly restored post-run (not MiuMjau).
Files: `includes/Smaily/RecEngine/CatalogPayloadBuilder.php`,
`includes/Integrations/WooCommerce/CatalogHookHandler.php`,
`tests/Unit/Smaily/RecEngine/CatalogPayloadBuilderTest.php`,
`tests/Unit/Integrations/WooCommerce/CatalogHookHandlerTest.php`,
`tests/Integration/RecEngineCatalogTest.php`, `docs/DECISIONS.md`,
`STATUS.md`. **Remaining**: the MiuMjau repair (retry of the 253 real
failed rows once this ships) is a human/orchestrator step, not part of this
change — see the ADDENDUM below for the mechanics (Event Log "Retry",
F3-44).)

Prior: 2026-07-21 (**PRO-1491 — MiuMjau's 253 failed `catalog.upsert`
rows (`d6_item_error field=category_path`) root-caused; mock divergence
closed, no CatalogPayloadBuilder change.** Live evidence: every failed row
had an empty `category_path`. Root cause, confirmed empirically (not just
read from code): `CatalogPayloadBuilder::primary_category_path()` returns ''
only when `get_the_terms( $id, 'product_cat' )` is genuinely empty for the
canonical product — and WooCommerce's OWN `WC_Post_Data::force_default_term()`
(hooked on the `set_object_terms` action, `class-wc-post-data.php`) re-asserts
the store's `default_product_cat` ("Uncategorized") on ANY `wp_set_object_
terms( …, array(), 'product_cat' )` clear attempt, confirmed live in wp-env
(a `WC_Product::save()` + explicit clear still healed back to
"uncategorized"). So an empty category_path is NOT "a merchant removed the
category" (that self-heals) — it only happens for a post whose `product_cat`
relationship was **never established through any `wp_set_object_terms` call
at all** (the self-heal hook never fires), e.g. a bulk-import / migration
tool that writes `wp_posts` + product meta directly, or a WPML/WCML
translation "stand-in" row created outside WC's normal product-save path.
Contract check (§3, `category_path` | YES | non-empty) confirms this is a
REQUIRED field with NO omit-on-empty allowance, matching the plugin's own
pre-existing, deliberate decision (F3-39, 2026-06-14; `primary_category_
path()`'s docblock): the builder must never invent a fallback category —
the engine's 400 is the intended merchant-data-gap signal, not a bug. That
decision stands unchanged; **no code change to CatalogPayloadBuilder**.
**Mock-divergence closed** (LESSONS §2.3/§2.4 — a real one): the integration
mock (`tests/Integration/Fixtures/mock-rec-engine/router.php`,
`/api/v1/ingest/catalog`) used to silently ACCEPT an empty/blank
`category_path`, hiding exactly the failure MiuMjau hit 253 times. It now
per-item-rejects it (`errors: [{field: 'category_path', message: 'String
must contain at least 1 character(s)'}]`), matching the live engine's D6
response byte-for-byte (message text taken from the live evidence).
`docs/audits/MOCK_DIVERGENCE_AUDIT.md`'s catalog row updated ("Mock now:
enforced (PRO-1491)"). **Tests**: 1 new unit test
(`CatalogPayloadBuilderTest::test_category_path_is_empty_string_with_no_
fallback_when_product_has_no_categories` — pins '' stays '', never a
fallback); 1 new integration test
(`RecEngineCatalogTest::test_uncategorized_product_upsert_is_rejected_by_
the_engine_and_marked_failed` — a fixture built via raw `wp_insert_post()` +
meta, bypassing `WC_Product::save()` on purpose so the self-heal never
fires, reproducing the real MiuMjau shape; asserts the flusher marks it
`failed`, never silently `sent` nor silently dropped). **Recovery path for
the 253 MiuMjau rows** (code-verified, not yet executed — a post-release
human/orchestrator step): `IngestFlusher::row_to_object()` loads the product
FRESH via `wc_get_product()` and rebuilds the payload at EVERY send,
including a retry (F3-44 holds for catalog rows) — so once each affected
product is given a real `product_cat` term in wp-admin, using the Event Log's
existing "Retry" on the failed rows is sufficient; a full catalog re-backfill
is NOT required (though it would also work). Identifying which 253 products
need a category (via the stored `sent_payload`/sku on each failed row,
F3-44) and assigning one is the merchant/human step this doesn't automate.
Gates: `ci:strict` exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit unit
583/583 incl. the 1 new, vitest 248/248 unchanged). Integration:
`sg docker -c "composer run test:integration"` OK (161 tests, 809 assertions,
up from 160/804 — the 1 new PRO-1491 case), dev sandbox tenant "Smaily
Connect test" correctly restored post-run.
Files: `tests/Integration/Fixtures/mock-rec-engine/router.php`,
`tests/Integration/RecEngineCatalogTest.php`,
`tests/Unit/Smaily/RecEngine/CatalogPayloadBuilderTest.php`,
`docs/audits/MOCK_DIVERGENCE_AUDIT.md`, `docs/DECISIONS.md`, `STATUS.md`.)

Prior: 2026-07-21 (**PRO-1197 — docs currency refresh: ARCHITECTURE.md
/ API.md brought current with everything that landed since the 2026-07-13
DEVELOPER.md touch-up** (`git log 700d870..HEAD`). No rewrite — a targeted
pass fixing every claim the delta made stale, per Erkki's scope correction
(the three docs already exist; FAQ/TROUBLESHOOTING stays deliberately
deferred, untouched). Changes: ARCHITECTURE.md §4 "Storefront pieces" —
point 1 (browse beacon) now notes `cart_add`/`cart_remove` resolve their sku
server-side via `Support\SkuResolver` from a proxy-internal `product_id`
(PRO-1390, closing the raw-merchant-SKU collision gap); point 2 (attribution
capture) now describes BOTH the pre-existing server-side `LandingCapture` AND
the browser-side `RecEngineClient.captureUrlParams()` added consent-
independently by PRO-1388 (the MiuMjau full-page-cache case where PHP never
runs on a landing hit); point 3 (identity merge) now covers BOTH the
pre-existing `wp_login` `/identity/merge` call AND the PRO-1389 ongoing-
session server-side `customer_email` injection in `BeaconEndpoint`. API.md
§1.1's `/relay` defense-layer list reordered/expanded to match the real code
path (PRO-1446's batch-cap-before-resolve ordering, PRO-1390's cart-sku
resolution step, PRO-1389's identity-injection step before the profiling
gate); §4's consent-order paragraph now notes `captureUrlParams()` runs
before consent resolution; §6's contract pointer now names the v1.5.0 §14
notifications-ingest addition (PRO-1447, no plugin caller yet). DEVELOPER.md
reviewed against the same delta — no edit needed (only a version bump touched
build/test/release-relevant files in the window). `docs/INDEX.md` rows +
"Last reviewed" note updated to record the refresh. Verified every touched
claim against the actual code (`BeaconEndpoint.php`, `beacon-core.ts`,
`rec-engine-client.ts`, `StorefrontBeacon.php`), not just the commit messages.
`ci:strict` exit=0 (docs-only change; no code touched). Files:
`docs/ARCHITECTURE.md`, `docs/API.md`, `docs/INDEX.md`, `STATUS.md`.)

Prior: 2026-07-21 (**PRO-1389 — ongoing-session browse identity:
server-side email injection in the `/relay` proxy** (design approved by
Erkki 2026-07-21). `IdentityHookHandler` only binds identity on `wp_login`,
so a customer who stays logged in browsing forever never got identity
attached to their browse events — `StorefrontBeacon`'s own docblock flagged
this as a deferred enhancement. `BeaconEndpoint::attach_logged_in_identity()`
now closes it: after the abuse/rate-limit filtering and before the D6 send,
it resolves the visitor server-side via `resolve_logged_in_email()` —
`wp_validate_auth_cookie( '', 'logged_in' )` against the real WP `logged_in`
auth cookie, deliberately NOT a page-embedded REST nonce (the beacon sends
none, and a page-embedded nonce breaks under full-page caching — the
MiuMjau reality, PRO-1388) — and attaches `customer_email` (contract §6) to
every event in the batch actually forwarded. The client (`enrich()`) still
NEVER sends `customer_email` (F3-49's data-minimization is unchanged — this
is the one sanctioned server-side exception); the email never reaches the
JS blob or the `/relay` response. Consent does not weaken: event existence
stays gated on the JS marketing-consent gate alone; injection additionally
checks the (a).1 `ProfilingConsent` gate for the resolved email BEFORE
attaching it — an opted-out contact's event forwards unchanged (still
anonymous), never dropped. Wire-shape verified before coding: contract §6
already lists `customer_email` ("Identity hint (if user is logged in)") and
`BeaconEndpoint::EVENT_FIELDS` already whitelisted it (unused until now); the
mock's `has_identity`/D6 sub-count logic already read it too. One mock gap
found and fixed in the same pass (CC-8): the mock's `last_browse_events` test
introspection projection only carried `event_id`/`smaily_visitor_token`/`sku`
— `customer_email` was silently dropped from that projection (though it WAS
read for the `with_customer_match` sub-count), which masked the new field in
a first integration run; added it (omitted entirely when absent, not as an
empty string, so `assertArrayNotHasKey` stays meaningful).
Tests: 6 new unit tests (`tests/Unit/REST/BeaconEndpointIdentityTest.php`,
Brain\Monkey + a protected `resolve_logged_in_email()` seam mirroring
`LandingCaptureTest`'s `headers_already_sent()` pattern) covering
logged-in+consenting attach, anonymous no-attach, opted-out
forward-unchanged-not-dropped, a gate-present-but-not-opted-out control, the
response never carrying the email, and a multi-event batch all getting the
same email. 3 new integration tests in `RecEngineBrowseProxyTest.php` drive
the REAL cookie-validation path end-to-end (no doubled seam): a real WP user
+ a real `logged_in`-scheme auth cookie value captured via the
`set_logged_in_cookie` action (fired before any header write) and installed
into `$_COOKIE`, exactly as a browser would present it. `ci:strict` exit=0
(PHPCS 0 errors, PHPStan clean, PHPUnit unit 580/580 incl. the 6 new, vitest
248/248, tsc/eslint clean — JS untouched). Integration: `sg docker -c "bash
bin/run-integration-tests.sh"` OK (160 tests, 804 assertions, up from 157/791
— the 3 new PRO-1389 cases), dev sandbox tenant "Smaily Connect test"
correctly restored post-run. Docs updated in the same pass: DECISIONS.md
(new PRO-1389 entry, explicit ADDENDUM to F3-49 — F3-49 not reversed),
CLAUDE.md (F3-49 section addendum), `docs/DATA_MODEL_GDPR.md` +
`docs/site/index.html` (EN+ET both) — the "browse carries no email" claim
updated to note the logged-in, non-opted-out exception. Live end-to-end
verification on a real store/engine (does a real logged-in shopper's browse
event actually reach the live engine with `customer_email`) is a human
acceptance item — not yet done. Files: `includes/REST/BeaconEndpoint.php`,
`includes/Integrations/WooCommerce/StorefrontBeacon.php`,
`tests/Unit/REST/BeaconEndpointIdentityTest.php` (new),
`tests/Integration/RecEngineBrowseProxyTest.php`,
`tests/Integration/Fixtures/mock-rec-engine/router.php`, `docs/DECISIONS.md`,
`CLAUDE.md`, `docs/DATA_MODEL_GDPR.md`, `docs/site/index.html`.)
**Follow-up (quality-review efficiency finding, same day):** `attach_logged_in_
identity()` now returns the resolved email's already-checked `may_profile()`
decision, and `filter_by_profiling()` reuses it for matching events instead of
re-checking each one — up to 101 consent-transient lookups per batch collapses
to 1 (differing client-supplied emails still get their own per-event check,
unchanged); 2 new unit tests pin the call count, `ci:strict` + the
`RecEngineBrowseProxyTest` integration filter stayed green, no behavior change.

Prior: 2026-07-21 (**PRO-1445 — StorefrontBeacon product-page `sku`
resolution now unit-tested** (closes the pre-existing gap called out in the
PRO-1390 record below). Investigation: the integration-harness limitation
(plain `TestCase`, no `WP_UnitTestCase`/`go_to()`, can't drive `is_product()`)
only blocks testing WHICH page type fires — the product→canonical-key
resolution itself (`Support\SkuResolver::resolve()` + `CatalogPayloadBuilder::
primary_category_path()`) is a pure function of a `WC_Product` object and
doesn't touch any conditional tag, so it's a legitimate unit-test seam.
Extracted `StorefrontBeacon::page_context()`'s product branch into a new
public `product_context( \WC_Product $product ): array` method (behavior-
identical — `page_context()` now calls it via `array_merge`), and added
`tests/Unit/Integrations/WooCommerce/StorefrontBeaconTest.php` (3 tests,
`fake_product`/`WC_Product` shim pattern mirroring
`CatalogPayloadBuilderTest`) asserting the returned `sku` is always
`woo-{id}` even when the fake product carries a merchant SKU shaped like the
PRO-1390 live evidence (an EAN) — the exact bug class a regression here would
reproduce. Updated the CLAUDE.md "Browse browser-timing is NOT live-walk-
covered" note to record the split (conditional-tag branching still manual/
pilot-only; the key-resolution part is now unit-covered). `ci:strict`
exit=0 (PHPCS 0 errors, PHPStan clean, PHPUnit unit 574/574 incl. the 3 new,
vitest 248/248, tsc/eslint clean). Integration suite re-run: `sg docker -c
"bash bin/run-integration-tests.sh"` OK (157 tests, 791 assertions), dev
sandbox tenant "Smaily Connect test" correctly restored post-run (not
MiuMjau) — unaffected, since the existing `StorefrontBeaconTest` integration
test (the `other`-default case) is unchanged. Files:
`includes/Integrations/WooCommerce/StorefrontBeacon.php`,
`tests/Unit/Integrations/WooCommerce/StorefrontBeaconTest.php`, `CLAUDE.md`.)

Prior: 2026-07-21 (**v3.7.2 — RELEASED** (gates green; built/gated by the
worker pass, published by the orchestrator the same session — see the
publication note at the end of this entry). Version bumped in all four places
(`smaily-connect.php` header + `SMAILY_CONNECT_VERSION` +
`SMAILY_CONNECT_PLUGIN_VERSION`, `package.json`, `readme.txt` Stable
tag/Changelog/Upgrade Notice) plus the three test pins (`ConstantsTest.php`,
`tests/bootstrap.php`, `tests/phpstan-bootstrap.php`), committed first
(`bc7bcc9`, pushed to `main`). Content since the v3.7.1 release record
(`f470faf`): the PRO-1388 consent-independent browser-side attribution
capture fix + its tests (`6d8bd6d`/`f2ffd50`), the PRO-1447 contract sync to
v1.5.0 (`ea76f8d`, doc-only), and the PRO-1436 PCP-suppression comments
(`5f9b031`, comment-only) — the only user-facing changelog item is the
attribution-capture fix. `npm run build:admin && npm run build:client`
(client entry `dist/public/js/sc-runtime.js`); blocks built (`composer run
install-block-modules && composer run build`); i18n rebuild SKIPPED (no
`admin/src`/`.po` changes since v3.7.1, and the on-disk `.mo`/admin-bundle
`-et-…json` confirmed still current from the 3.7.1 build). `composer install
--no-dev --optimize-autoloader` → `composer run package` → `composer
install` (dev restored). ZIP verified: v3.7.2 in all three version strings,
required present (`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`,
`blocks/*/build/*`, `vendor/autoload.php`, `languages/smaily-connect-et.mo`
+ the admin-bundle et JSON), dev artifacts/tests/docs/node_modules/admin-src
absent, `composer.json` present, **1 116 466 B** (vs. v3.7.1's 1 114 299 B —
a ~2.2 KB increase in line with the delta's new beacon-core/rec-engine-client
code + contract doc). **PCP against the built ZIP** (unzipped to
`smaily-connect-pkg` in the wp-env `…-cli-1` container, never the
bind-mounted `smaily-connect` dir; `--slug=smaily-connect`): exactly the
expected set — only the intentional `plugin_updater_detected` ERROR (F3-35),
nothing else. The `due_rows()` method in `CartSessionStore.php` (same
`{$this->table_name()}` interpolation pattern as the sites PRO-1436
suppressed) was specifically checked and did NOT get flagged by PCP.
Container temp copies cleaned up afterward. **`ci:strict` exit=0** (PHPCS 0
errors, PHPStan clean, PHPUnit unit 571/571, vitest 248/248, tsc/eslint
clean). **Integration suite RE-RUN in full**: `sg docker -c "composer run
test:integration"` OK (157 tests, 791 assertions), dev sandbox tenant
"Smaily Connect test" correctly restored post-run (not MiuMjau). **Delta
security audit**: the two PRO-1388 beacon commits (the consent surface) were
adversarially reviewed — `captureUrlParams()` still writes only the three
attribution cookies, creates no session and sends nothing pre-consent, no new
injection/XSS surface, cookie flags sane; verdict PASS, 0 findings. Full
report `docs/audits/2026-07-21-SECURITY_DELTA_AUDIT_V372_GATE.md` + register
row in `docs/audits/INDEX.md`. **Published (orchestrator step, same
session):** `gh release create v3.7.2` on `erkkimarkus/smaily-wordpress-plugin`
— asset verified (1 116 466 B), Latest, non-prerelease, tag on the bump commit
`bc7bcc9` per the v3.7.1 convention. **PRO-1450 resolved in the same pass:**
the `v3.7.0` tag force-repointed `742f3b8` → `367c304` (its bump commit,
matching the convention); the v3.7.0 GH release re-verified intact afterwards
(asset present, still public, not draft/prerelease). MiuMjau updated to
v3.7.2 the same day (Erkki) — the PRO-1388 identified-session re-measurement
awaits post-release traffic.

Prior: 2026-07-21 (**PRO-1436 — suppressed the 4 false-positive PCP
warnings from the PRO-1195 abandoned-cart code, gate-reviewed at v3.7.0/
v3.7.1.** `CartAbandonmentSweeper.php:78` (`self::FILTER_MAX_AGE` —
`DynamicHooknameFound`, PCP can't resolve a class-constant hook name) got the
same `phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.
DynamicHooknameFound` comment already used at the 3.2.0 gate
(`ContactLanguageResolver.php`). `CartSessionStore.php:83,302,321`
(`UnescapedDBParameter` — PCP's `PluginCheck.Security.DirectDB.
UnescapedDBParameter` sniff) each got a narrow `phpcs:ignore` stating the
table name is `$this->table_name()`, a hardcoded internal constant (never
user input), and every real value is `$wpdb->prepare()`d. No behavior change,
comment-only diff; `phpcbf` re-aligned one unrelated assignment the comment
insertion displaced. Verified against a freshly built ZIP in the wp-env
`…-cli-1` container (`smaily-connect-pkg`, `--slug=smaily-connect`): PCP now
shows **only** the intentional `plugin_updater_detected` (F3-35) — the 4
PRO-1436 warnings are gone, nothing new appeared. `ci:strict` exit=0 (PHPCS 0
errors, PHPStan clean, PHPUnit unit 571/571, vitest 248/248, tsc/eslint
clean). Integration suite not re-run (comment-only PHP diff, no behavior
change).

Prior: 2026-07-17 (**PRO-1447 — contract re-synced byte-identical to
v1.5.0 (engine `3316261`, md5 `5c2aafa8…`).** Doc-only delta since our
`945b7ad`/v1.4.1 sync: MINOR bump (PRO-1438 Phase 2) adds §14 `POST
/api/v1/notifications/ingest` — the external HTTP ingest path for
Notifications 2.0 (Connect plugin / Smaily core / future services push
events into the merchant console notification drawer), plus the matching
TOC entry, rate-limit table row, and a new `notifications_ingest` key on the
setup-exchange endpoints map. CC-8 conformance verified: purely **additive**
— no existing wrapper key, required field, enum, or field was removed or
changed shape; confirmed via `diff` against the prior copy before syncing.
No plugin code or mock change made or needed — §14 has no caller yet (no
plugin/Smaily-core producer exists at lock time per the engine's own
changelog entry); this is a "documented ahead of any integration" sync.
Gates: `bash bin/check-contract-staleness.sh` green (`OK: … byte-identical
with engine main … engine commit 331626188a47b841bb4321aa511d8037baa838e2`).
Prior:
**PRO-1388 — browser-side attribution capture made
consent-INDEPENDENT.** Follow-up to the same-day audit below: a live probe of
a `?smaily_vt=...` MiuMjau landing showed no `Set-Cookie` at all, because
MiuMjau serves storefront pages from a full-page cache — PHP (and
`LandingCapture`) never runs on most landing hits, so the server-side fix
audited earlier today doesn't reach those requests. `RecEngineClient.
captureUrlParams()` (`public/js/lib/rec-engine-client.ts`) no longer gates on
consent — it now runs unconditionally, called from `beacon-core.ts`'s
`init()` BEFORE consent is resolved. Scope stayed narrow: this method writes
only the three attribution cookies (visitor/rec_id/context) and never creates
the anonymous session cookie or sends anything — `ensureSession()`,
`track()`/`flush()`, and cart-listener attachment are unchanged and remain
fully consent-gated. `LandingCapture` (F3-46) is untouched — it stays as
defense-in-depth for JS-disabled visitors on a cache-miss hit. New vitest
cases: capture-without-consent still writes the cookie + strips the URL
(`rec-engine-client.test.ts`), and end-to-end through `init()` — captured
pre-consent with no session/send, then a later consent grant sends an event
carrying the pre-consent `smaily_visitor_token` (`beacon-core.test.ts`). Full
decision + rationale in `docs/DECISIONS.md` PRO-1388 (updated in the same
commit); merchant docs site (`docs/site/index.html`, EN+ET) tweaked — the
Campaign Intelligence attribution bullet no longer claims "server-side" as
the sole mechanism. `ci:strict` green (JS-only diff; no PHP touched, so
`test:integration` not re-run).

Prior: 2026-07-17 (**PRO-1388 — AUDITED, no code change needed:
the `smaily_vt` capture-timing race is already closed.** The engine team
(PRO-1382) reported that `beacon-core.ts`'s client-side, consent-gated
`captureUrlParams()` loses the `?smaily_vt=...` param when a visitor decides
the cookie-consent banner on a later page than the landing (MiuMjau: 373
email clicks → 7 identified customers). Erkki's decision was to capture
`smaily_vt` server-side, consent-independent, in `LandingCapture`. Code
audit found this was **already implemented** by F3-46 (`211395a`,
2026-06-26, shipped v3.1.0+) — `LandingCapture::resolve()`/`capture()`
already writes `smaily_vt` into the `smaily_rec_uid` cookie on
`template_redirect`, gated only on `is_connected()` +
`smaily_connect_capture_attribution`, never on consent; the JS
`RecEngineClient.enrich()` reads that cookie at `track()`-time regardless of
when consent was granted. Existing tests already cover exactly this
scenario (`LandingCaptureTest::test_captures_full_link_into_config_named_cookies`,
`rec-engine-client.test.ts`'s `'carries smaily_visitor_token from the
visitor cookie when present'`). No plugin diff — superseded same day by the
entry above once the "open question" was live-probed and answered.
`ci:strict` / integration not re-run (no code touched).

Prior: 2026-07-17 (**PRO-1341 — v3.7.1 RELEASED.** Full local
build/verify/release sequence run after the version-bump commit `c25a4bb`
(pushed to `main`): `npm run build:admin && npm run build:client` (client
entry confirmed `dist/public/js/sc-runtime.js`); blocks built (`composer run
install-block-modules && composer run build`); i18n rebuild SKIPPED (no
`admin/src`/`languages` changes in the `a2326df..HEAD` delta, and the
`.mo`/admin-bundle `-et-…json` on disk were still the ones built for the
3.7.0 cut hours earlier); `composer install --no-dev --optimize-autoloader` →
`composer run package` → `composer install` (dev restored). ZIP verified:
v3.7.1 in all three version strings, required present (`dist/admin/admin.js`,
`dist/public/js/sc-runtime.js`, `blocks/*/build/*`, `vendor/autoload.php`,
`languages/smaily-connect-et.mo` + the admin-bundle et JSON), dev artifacts/
tests/docs/node_modules/admin-src absent, `composer.json` present,
1 114 299 B (vs. v3.7.0's 1 110 616 B — a ~3.7 KB increase in line with the
delta's new `RecEngineBrowseProxyTest` fixtures/code, no `blocks/node_modules`
leak). **PCP against the built ZIP** (unzipped to `smaily-connect-pkg` in the
wp-env `…-cli-1` container via `/tmp`, never the bind-mounted `smaily-connect`
dir; `--slug=smaily-connect`): exactly the expected set — the intentional
`plugin_updater_detected` ERROR (F3-35) + the same 4 PRO-1436 false-positive
WARNINGs from the cart code (`CartAbandonmentSweeper.php:78`
`DynamicHooknameFound`, `CartSessionStore.php:83,302,321`
`UnescapedDBParameter` ×3) — nothing new. Container temp copies cleaned up
afterward. **`ci:strict` exit=0** (PHPCS 0 errors, PHPStan no errors, PHPUnit
unit 571/571, vitest 245/245, tsc/eslint clean) run after the bump commit to
confirm the pins. Integration suite not re-run for this gate specifically —
the `RecEngineBrowseProxyTest`/`BeaconEndpointTest` changes in this delta were
already integration-proven the same day at the PRO-1446 fix commit
(`sg docker -c "composer run test:integration"` 157/157, sandbox tenant
correctly restored). **Released via `gh release create v3.7.1
smaily-connect.zip --repo erkkimarkus/smaily-wordpress-plugin --target main
--title "v3.7.1"`** — non-prerelease, confirmed **Latest**; asset
byte-verified (1 114 299 B, matches the local build exactly).
[Release](https://github.com/erkkimarkus/smaily-wordpress-plugin/releases/tag/v3.7.1).

Prior: 2026-07-17 (**PRO-1341 — v3.7.1 version bump PREPARED**
(`smaily-connect.php`, `package.json`, `readme.txt` Stable tag/Changelog/
Upgrade Notice, the three test version-pins). Content since the v3.7.0 release
(effective baseline `a2326df`, the last commit of the 3.7.0 release — the
published `v3.7.0` git tag itself points one commit earlier at `742f3b8`, a
pre-existing tag-placement anomaly from that cut; not touched here, see
FOLLOW-UPS): PRO-1390 browse `cart_add`/`cart_remove` now key on the canonical
`woo-<id>` instead of the raw merchant SKU (`674c04c`) + its live-walk
verification (`d091e72`); PRO-1342 removed a bogus uninstall.php legacy-option
entry that was actually the cart table-name suffix, a harmless no-op
(`bd72c58`); a docs-wording correction on an earlier audit's overstated
closure claim (`a0a86ac`); the v3.7.1 gate delta security audit — 1 HIGH,
release-blocking (`490792e`) — and its fix, `PRO-1446`: `/relay`'s
`resolve_cart_product_skus()` now runs after a pure size-cap check
(`size_guard()`) so an oversized batch is rejected before any
`wc_get_product()` lookup, closing the DoS path (`5ee1366`), independently
re-verified adversarially (`76e9736`, `0ea25d9`). i18n: no admin/src or
languages changes in this delta (`git diff a2326df..HEAD -- admin/src
languages` empty) and the `.mo`/`-et-…json` artifacts on disk are still the
ones built for the 3.7.0 cut this morning — `bin/build-i18n.sh` SKIPPED per
the CLAUDE.md rule (nothing to regenerate). Build/package/PCP/ci:strict/
GH-release steps follow; this note is updated again once the release is
actually cut and verified.

Prior: 2026-07-17 (**PRO-1446 — FIXED the v3.7.1 gate's release-blocking
HIGH finding: `/relay` cart-sku resolution ran unbounded before the batch-size
cap.** `BeaconEndpoint::handle()` (`5ee1366`) now runs a cheap, pure size check
(`size_guard()`, extracted out of `validate_batch()`, which now delegates to
it) on the raw `events` array **before** `resolve_cart_product_skus()`'s
per-event `wc_get_product()` loop — so an oversized batch is rejected with the
same `400`/`"Batch exceeds the 100-event cap."` it always got, but before any
DB-backed lookup runs; the resolve loop can never execute over more than the
100-event cap. Valid (≤100-event) `cart_add`/`cart_remove` batches are
unaffected — resolution still runs before `validate_batch()`'s field whitelist
strips the proxy-internal `product_id`, so `woo-<id>` resolution is unchanged
(existing PRO-1390 unit/integration tests stayed green). New regression test
`RecEngineBrowseProxyTest::test_oversized_batch_is_rejected_before_any_product_lookup`
sends a 101-event batch and asserts, via a `woocommerce_product_class` filter
counter, zero `wc_get_product()` calls and a 400 with the engine never
contacted. `ci:strict` green (PHPCS 0 errors, PHPStan clean, PHPUnit unit 571,
vitest 245) and `sg docker -c "composer run test:integration"` green (157/157,
sandbox tenant `Smaily Connect test` correctly restored, not MiuMjau). Audit
report disposition + `docs/audits/INDEX.md` row updated to FIXED. **v3.7.1 may
now proceed** once the rest of the release-gate checklist (PCP against the
built ZIP, etc.) is run. **Independently re-verified (adversarial pass,
2026-07-17, Claude/Fable 5): no bypass of the cap, no remaining disproportionate
per-event cost under it, the regression test would genuinely fail on a
reverted ordering, and no error-shape drift from the refactor — Finding 1 holds
fixed.** Full detail:
[`docs/audits/2026-07-17-SECURITY_DELTA_AUDIT_V371_GATE.md`](docs/audits/2026-07-17-SECURITY_DELTA_AUDIT_V371_GATE.md).)

Prior: 2026-07-17 (**v3.7.1 gate delta security audit — 1 HIGH,
release-blocking.** Scope: `a2326df..HEAD` (the v3.7.0 tag to now — PRO-1390
`/relay` cart-sku fix + 3 trivial doc/cleanup commits). Finding: in
`BeaconEndpoint::handle()`, the new `resolve_cart_product_skus()` (added by
`674c04c`) calls `wc_get_product()` per event over the FULL raw `events`
array **before** `validate_batch()`'s `MAX_EVENTS=100` cap runs — on the
plugin's one public, unauthenticated route. A single crafted POST with a
large `events` array (well within default body-size limits; the fixed-window
rate limiter only throttles request count, not events-per-request, and does
nothing on the FIRST request) forces tens of thousands of DB-backed lookups
before the batch is rejected as oversized — a real, cheap, unauthenticated
DoS/resource-exhaustion path that did not exist before PRO-1390. **Do not tag
v3.7.1 until this is fixed** (reorder so the size/shape cap runs before the
per-event resolve loop) and re-verified. Everything else in the delta
(PRO-1342 `uninstall.php` cleanup, the live-walk record, the docs-wording
correction) is clean/trivial. Full detail:
[`docs/audits/2026-07-17-SECURITY_DELTA_AUDIT_V371_GATE.md`](docs/audits/2026-07-17-SECURITY_DELTA_AUDIT_V371_GATE.md).
No code changed by this audit — it is a finding, not a fix.)

Prior: 2026-07-17 (**docs correction — the 2026-07-16 delta-audit
report (item 14) and its `docs/audits/INDEX.md` row overstated closure: only
Info finding #2 (cart-PII docs gap) from the 2026-07-13 audit was closed by
that delta (PRO-1343/PRO-1194/PRO-1405); finding #1 (the bogus `uninstall.php`
legacy-options entry, see the PRO-1342 entry directly below) was fixed
separately. Wording corrected in both docs; no code change.)

Prior: 2026-07-17 (**PRO-1342 — removed the bogus `uninstall.php`
legacy-options entry.** The 2026-07-13 delta audit's Info finding #1: `$legacy_
options` (deleted via `delete_option()`) contained `'smaily_connect_abandoned_
carts'`, which is actually the legacy CART **TABLE** suffix
(`integrations/woocommerce/cart.class.php::ABANDONED_CART_TABLE_NAME` /
`Migration\LegacyCartDrain::LEGACY_TABLE_SUFFIX`), not an option name —
`delete_option()` on it was a harmless no-op (grep-confirmed: no code ever
writes an option by that name). Fix: removed the entry from `uninstall.php`'s
`$legacy_options`; no replacement was needed since no such option exists — the
real abandoned-cart options (`smaily_connect_abandoned_cart_cutoff`/`_status`)
were already listed separately. **Table-drop behavior is unchanged by design**
(DECISIONS.md PRO-1195: the legacy cart table is deliberately kept, not
dropped, at uninstall — "safe rollback; schema removal is a later one-way
door") — this fix touches only the dead option-list entry. No test previously
pinned the full `$legacy_options` array content (`UninstallCleanupTest` only
source-pins specific option/prefix constants), so none was added — matches
existing test-coverage pattern. `ci:strict` exit=0 (PHPUnit unit 571, vitest
245); integration suite not re-run (delta is `uninstall.php` + docs only, no
runtime path affected). Updated the 2026-07-13 audit report's finding-1
disposition to FIXED and the `docs/audits/INDEX.md` register row's inline
outcome text to match. Files: `uninstall.php`,
`docs/audits/2026-07-13-SECURITY_QUALITY_RE_AUDIT_PRO1195_CART_REWRITE.md`,
`docs/audits/INDEX.md`.)

Prior: 2026-07-17 (**PRO-1390 live-walk verification — CONFIRMED against
the real sandbox engine.** The dev wp-env held a live connection to the
"Smaily Connect test" sandbox tenant (restored earlier by the integration-test
snapshot guard) — checked non-secret options (`base_url=intelligence.smaily.com`,
`tenant_name=Smaily Connect test`, `is_connected=1`) before sending anything;
tenant was NOT MiuMjau, so the hard safety gate cleared. Ran the full
`RECENGINE_LIVE=1 node bin/walk-3.4-browse.cjs` — all 14 checks PASS (LIVE OK)
— proving the browse pipeline still works end-to-end live; this walk predates
PRO-1390 so it sends `sku` directly and doesn't exercise the new
`product_id`-resolution path. Added one targeted live check (temp `wp
eval-file`, not committed) that dispatches a `cart_add` event through the real
`/relay` proxy carrying `product_id` (no `sku`, mirroring the real JS) for an
EXISTING dev-site product (id 4334) whose merchant SKU
(`GDPR-f213d142-…`) is deliberately NOT in `woo-<id>` shape, and captured the
exact JSON POSTed to the engine's `ingest/browse` endpoint via the
`http_api_debug` hook. Result: the engine-bound event carried
`sku = "woo-4334"` (not the merchant SKU), and the engine accepted it
(`200 {"processed":1,"errors":[]}`). Confirms the PRO-1390 fix
(`BeaconEndpoint::resolve_cart_product_skus`) end-to-end against the real
sandbox — not just the mock. Residue: the walk's + targeted check's browse
events (+1 seeded customer `walk-browse-*@example.test`) landed in the
sandbox tenant only — expected/fine per the walk's own convention; no WC
entities created or left behind (used an existing product, read-only). No
code changed by this verification pass.)

Prior: 2026-07-17 (**PRO-1390 — browse cart_add/cart_remove now key on
the canonical `woo-<id>`, not the merchant SKU.** Engine prod evidence
(MiuMjau) showed `browse_events.sku` arriving raw for `product_view`/
`cart_add` (e.g. `aatc-20-1`, an EAN `4022858617724`) — only 6% of
`product_view` rows joined to the catalog. Investigation found `product_view`
already resolves correctly (`StorefrontBeacon::page_context()` has called
`Support\SkuResolver::resolve()` since PRO-1224, 8fa04f5) — the live bug was
`cart_add`/`cart_remove` only: `beacon-core.ts`'s `attachCartListeners` read
the WC AJAX button's `data-product_sku` (WooCommerce's own raw merchant-SKU
attribute). Fix: the JS now reads `data-product_id` instead (WC's
add-to-cart.js won't even fire the AJAX call without one, so it's always
present) and sends it as a new **proxy-internal** `product_id` field — never
forwarded to the engine (not in `BeaconEndpoint::EVENT_FIELDS`). A new
`BeaconEndpoint::resolve_cart_product_skus()` resolves it server-side via
`wc_get_product()` + `Support\SkuResolver::resolve()` (same resolver as
catalog/orders, incl. multilingual canonicalization — a variation keys on its
own id, matching catalog) into `sku`, called before `validate_batch()` in
`handle()`. An unresolvable `product_id` (e.g. a deleted product) drops `sku`
rather than forwarding a guessed value — logged once per batch via
`DebugLog`, never a silent wrong-key send. Tests: +1 PHP unit
(`product_id` dropped by the whitelist even on a hand-crafted request), +6
PHP integration (`RecEngineBrowseProxyTest` — cart_add/cart_remove resolve to
`woo-<id>` and NOT the merchant SKU seeded on the test product, a variation
keys on its own id not the parent, an unresolvable id drops `sku`,
`product_view` is unaffected/unchanged), +3 vitest (`beacon-core.test.ts`
cart listeners now assert `product_id` not `sku`, incl. the jQuery
number-vs-string `.data()` coercion case; `rec-engine-client.test.ts`
`product_id` passthrough). `ci:strict` exit=0 (PHPUnit unit 571, vitest 245).
Integration green: `sg docker -c "bash bin/run-integration-tests.sh --filter
RecEngineBrowseProxyTest"` 15/15, then the full
`sg docker -c "composer run test:integration"` 156/156 (snapshot guard
restored the sandbox `Smaily Connect test` tenant afterward). **No live-walk
run** — `bin/walk-3.4-browse.cjs` needs `RECENGINE_LIVE=1` + a fresh SANDBOX
setup-token, which this pass didn't have; the mock-engine integration proof
above covers the wire shape (mock now also projects `sku` in
`last_browse_events` for this). Human acceptance / next live-walk: confirm
against the real sandbox engine that a `cart_add` sent from a live storefront
carries `sku = woo-<id>`. Out of scope (unchanged, pre-existing): `wishlist_*`
events have no JS producer yet; `StorefrontBeaconTest` still doesn't assert
the product_view `sku` branch (a pre-existing gap, not introduced here).
Files: `includes/REST/BeaconEndpoint.php`, `public/js/beacon-core.ts`,
`public/js/lib/rec-engine-client.ts`,
`tests/Integration/Fixtures/mock-rec-engine/router.php` (added `sku` to the
`last_browse_events` projection), `tests/Unit/REST/BeaconEndpointTest.php`,
`tests/Integration/RecEngineBrowseProxyTest.php`,
`public/js/beacon-core.test.ts`, `public/js/lib/rec-engine-client.test.ts`.)

Prior: 2026-07-17 (**PRO-1341 — v3.7.0 RELEASED.** Full local
build/verify/release sequence run end-to-end after the version bump (below):
`npm run build:admin && npm run build:client` (confirms the client entry name
is `dist/public/js/sc-runtime.js`, not `beacon.js`); blocks built
(`composer run install-block-modules && composer run build`); i18n rebuilt via
`bash bin/build-i18n.sh` inside the wp-env CLI container (PRO-1430 signup-guide
strings — only `#:` reference-line refresh landed in `.pot`/`-et.po`, no
msgid/msgstr change, committed separately as `39c606a` so `package:hash`
stamped clean); `composer install --no-dev --optimize-autoloader` →
`composer run package` → `composer install` (dev restored). **ci:strict
exit=0** (PHPCS 0 errors, PHPStan clean, PHPUnit unit 570, vitest 244,
tsc/eslint clean) run once after the version-bump commit to confirm the pins.
ZIP verified: clean build-hash `39c606a`, v3.7.0 in all three version strings,
required present (`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`,
`blocks/*/build/*`, `vendor/autoload.php`, `languages/smaily-connect-et.mo` +
the admin-bundle et JSON), dev artifacts/tests/docs/node_modules/admin-src
absent (grep-confirmed zero matches), 1 110 616 B (in line with recent
releases, no `blocks/node_modules` leak). **PCP against the built ZIP**
(unzipped to `smaily-connect-pkg` in the wp-env `…-cli-1` container, never the
bind-mounted `smaily-connect` dir): the single intentional
`plugin_updater_detected` ERROR (F3-35, stays until upstream merge) + 4 NEW
WARNINGs from the PRO-1195 cart code, both reviewed and confirmed false
positives, no fix applied — `CartAbandonmentSweeper.php:78`
`DynamicHooknameFound` on `self::FILTER_MAX_AGE` (PCP can't resolve a
class-constant reference to its literal value; the constant itself is
correctly `smaily_connect_`-prefixed — same false-positive class as the
3.2.0-gate `self::FILTER_*` findings, which WERE suppressed with
`phpcs:ignore` there; not done here — **follow-up**, see below) and
`CartSessionStore.php:83,302,321` `UnescapedDBParameter` ×3 on
`{$this->table_name()}` interpolation (the table name is a hardcoded internal
constant, not user input; every actual value goes through `$wpdb->prepare()`
with `%s`/`%d` — the same SQL already reviewed clean in the 2026-07-13
cart-rewrite security audit). Full detail + the release-gate row:
`docs/audits/INDEX.md` "3.7.0 release gate". **Released via `gh release
create v3.7.0 smaily-connect.zip --repo erkkimarkus/smaily-wordpress-plugin
--target main --title "v3.7.0"`** — non-prerelease, confirmed **Latest**;
asset byte-verified (1 110 616 B, matches the local build exactly). Prior:
2026-07-17 (**PRO-1341 — v3.7.0 version bump PREPARED**
(`smaily-connect.php`, `package.json`, `readme.txt` Stable tag/Changelog/
Upgrade Notice, the three test version-pins). Content since v3.6.1: PRO-1195
abandoned-cart rewrite onto the v3 pipeline (retires the legacy pass), PRO-1277/
PRO-1334 disabled-workflow dropdown filtering + preserve-and-flag across all 4
autoresponder surfaces (CF7, classic Widget, Gutenberg, Elementor), PRO-1194
fail-open GDPR window hardening (durable profiling opt-out registry + stale-
cache fallback before ever failing open), PRO-1336/PRO-1337 uninstall sweeps
(rec-engine connection state + the opt-out registry), PRO-1343/PRO-1405 WP
Privacy exporter/eraser now covers `smly_plus_cart_session` + friendly-name
rename to "Smaily Connect data", PRO-1430 the Integrations page "How to add a
Smaily signup form" guide (+ wording pass 742f3b8). Release-gate delta audit
already PASS at `c2d79a7` (0 Crit/High/Med/Low, 1 Info accepted — see the entry
below, unchanged). Build/package/PCP/ci:strict/GH-release steps follow;
this note is updated again once the release is actually cut and verified.
Prior: 2026-07-17 (**PRO-1341 — v3.7.0 gate delta re-audit
(post-PRO-1195) DONE: PASS, 0 Critical/High/Medium/Low, 1 Info.** Per the
re-audit policy (`docs/audits/INDEX.md`), a delta pass was required since the
2026-07-13 audit's baseline (`af9b52f`) because the intervening commits touch
the GDPR surface. Scope: `af9b52f..HEAD` (`bc5d6bc`, 8 commits) —
`GdprHandler`/`CartSessionStore` WP Privacy coverage for the abandoned-cart
tracker (PRO-1343) + the exporter/eraser friendly-name rename (PRO-1405) +
the privacy-policy template sign-off/port (PRO-1194, docs-only), plus the
unrelated PRO-1430 "How to add a Smaily signup form" guide (new `docsUrl`
boot-payload field + admin React UI + docs-site HTML). Verdict: the new
`rows_for_privacy_request()`/`delete_rows_for_privacy_request()` share one
prepared-SQL WHERE builder so export/erase can never target different row
sets; export drops the internal `id` + empty/null columns; the new `docsUrl`
field renders only as an auto-escaped JSX `href` sourced from the existing
filterable `Constants::docs_url()`; the new clipboard-copy button copies a
hardcoded constant only (no injection surface); docs-site additions are
static HTML with EN/ET parity, no external deps added. Confirms this delta
CLOSES both Info findings from the 2026-07-13 audit (cart tracker now has
real WP Privacy coverage and is documented in `DATA_MODEL_GDPR.md` + the
now-signed-off privacy-policy template). 1 new Info, accepted (no fix
needed at the current trust level): the `docsUrl` anchor has no scheme
allowlist unlike the T2-audit `isHttpUrl()` guard on an engine-origin URL —
but the source here is a developer-only PHP filter over a hardcoded https
default, not third-party network input; revisit if `docs_url()` ever
becomes admin-settings-writable. No `ci:strict`/PCP/live-walk run in this
pass — STATUS.md already records green gates per code-touching commit in
the delta (below); those remain separate v3.7.0 release-gate steps not yet
run. Report:
[`docs/audits/2026-07-16-SECURITY_QUALITY_DELTA_AUDIT_V370_GATE.md`](docs/audits/2026-07-16-SECURITY_QUALITY_DELTA_AUDIT_V370_GATE.md).
Prior: 2026-07-17 (**PRO-1430 — DONE: "How to add a Smaily signup
form" guide on the Integrations page (wizard Step 5 + Settings tab, both
render the same component).** Erkki (product owner) found no in-product
guidance for where a merchant actually places a Smaily signup form on each
supported surface. `Step5Integrations.tsx` gained a new
`SignupFormGuide` block below the existing install-status cards, one
card per surface, facts verified against the real source: **Shortcode**
(`[smaily_connect_newsletter_form]`, code block + one-click copy button
mirroring RssFeedSection's clipboard/fallback pattern, plus a "See all
attributes" link to the merchant docs site); **Gutenberg block** (real
title "Smaily Sign-Up Form", `blocks/newsletter-signup/src/block.json`);
**Elementor widget** ("Smaily Opt-In Form" under the "Smaily" category,
`integrations/elementor/newsletter-widget.class.php` +
`admin.class.php`); **Classic Widget** ("Smaily Classic Subscription
Widget", `includes/smaily-widget.class.php`); **Contact Form 7** (the
"Smaily for Contact Form 7" tab, `integrations/cf7/admin.class.php`).
Elementor/CF7 cards reuse the SAME `state.env.elementorPresent` /
`cf7Present` detection the existing CARDS grid already had — when the
host plugin is absent the card just says so in plain text (no dead
pointer). The docs link needed a NEW boot-payload field: `EnvDetector::
snapshot()` now emits `docsUrl` (= `Constants::docs_url()`, so the
`smaily_connect_docs_url` filter still governs it) — threaded through
`hydrate.ts` / `settings-reducer.ts` / `wizard-reducer.ts` /
`WizardState.env.docsUrl` (optional, empty-string default; the React
link renders only when non-empty, same gating style as `env.rss`).
`docs/site/index.html` `#set-integrations` gained sibling how-to
paragraphs (EN+ET) for the four non-shortcode surfaces — the shortcode
itself was already documented there since PRO-1338; sidebar nav
untouched (it doesn't itemize below `#set-integrations`). New msgids
added to `languages/smaily-connect.pot` + Estonian translations in
`-et.po` (`.mo`/JSON NOT rebuilt — gitignored, rebuilt at release time
per `bin/build-i18n.sh`). Tests: new
`admin/src/components/steps/Step5Integrations.test.tsx` (shortcode copy,
docs-link presence/absence, Elementor/CF7 presence branching, block/
widget names) + a new `EnvDetectorTest::
test_snapshot_carries_docs_url_from_constants` case. `ci:strict` green
(PHPCS 0 errors, PHPStan clean, PHPUnit unit 570 tests OK, JS 244 tests
+ typecheck OK); `sg docker -c "bash bin/run-integration-tests.sh"`
green (151 tests, 770 assertions), dev-site `smly_rec_*` snapshot
restored to the sandbox tenant ("Smaily Connect test", not MiuMjau)
afterwards. Human acceptance (Erkki using the real page to locate each
surface + the PRO-1334-style visual check) is OUTSTANDING — not
claimed here.
Prior: 2026-07-14 (**PRO-1194 — RESOLVED (docs-only): privacy-policy
template signed off + ported to the merchant docs site.** Erkki decided
(2026-07-14) the three items blocking sign-off: Smaily legal entity =
**Sendsmaily OÜ**; Smaily privacy-policy URL =
**https://connect.smaily.com/privacy** (cross-team issue PRO-1406 makes this
URL platform-agnostic separately — it's stable, so this doesn't wait on that);
lawful-basis framing (legitimate interest, Art 6(1)(f) GDPR, + Art 21
opt-out) **confirmed as drafted** — the merchant-legal-review caveat stays,
each merchant still needs their own counsel. `docs/DATA_MODEL_GDPR.md`: the
DRAFT banner flipped to SIGNED OFF, both template language blocks (EN+ET)
have the two placeholders replaced with the real values, and the "Open
placeholders" list is marked resolved (item 4 keeps the merchant-legal-review
caveat text unchanged, per Erkki). The signed-off template is now also
PORTED into `docs/site/index.html` (merchant-facing docs) as a new
"Privacy‑policy text for your store" subsection under Data & privacy — EN+ET
sibling blocks, same commit, nav updated to match; existing page structure
otherwise untouched. `docs/DECISIONS.md` gained a short PRO-1194 (sign-off)
entry recording the decision. Docs-only; `ci:strict` not required (no
PHP/JS/tests touched). Only the cross-team URL-platform-agnostic rework
(PRO-1406) rides separately — not blocking, PRO-1194 itself is closed. Prior:
2026-07-14 (**PRO-1405 — GdprHandler friendly name renamed
to "Smaily Connect data" DONE.** Erkki decided (2026-07-14) to rename the WP
Privacy exporter/eraser friendly name away from "Smaily Campaign Intelligence
data" — since PRO-1343 it also covers the `smly_plus_cart_session`
abandoned-cart tracker, which works independently of the rec-engine
connection, so the old name overclaimed scope. Both `register_exporter()`
and `register_eraser()` in `includes/Privacy/GdprHandler.php` now register
`__( 'Smaily Connect data', 'smaily-connect' )`. `languages/
smaily-connect.pot` + `-et.po` msgid/msgstr updated to match (Estonian:
"Smaily Connect andmed"); `.mo`/JSON artifacts NOT rebuilt here (gitignored,
rebuilt at release time per `bin/build-i18n.sh`). No test asserted the old
string literal, so none needed changing. `docs/site/index.html` and
`docs/DATA_MODEL_GDPR.md` don't name this exact label — re-grepped, no
changes needed. `ci:strict` green.
Prior: 2026-07-14 (**PRO-1343 — GdprHandler now covers the
`smly_plus_cart_session` abandoned-cart tracker DONE.** Erkki decided
(2026-07-14) to register real WP Privacy exporter/eraser coverage for this
table rather than rely on the ~24h auto-purge (the PRO-1194 follow-up below).
`GdprHandler::export()`/`erase()` now also call into
`CartSessionStore::rows_for_privacy_request()` /
`delete_rows_for_privacy_request()` (new store methods): matched by the
`email` column, plus (belt-and-suspenders) a row keyed to the requester's WP
user id in case its `email` column were ever empty — current write paths
always populate both together, so this is defensive, not the primary path.
Export surfaces one "Abandoned-cart session" item per row (all populated
columns except the internal `id`); erase deletes the same row set and
reports `items_removed` truthfully. `GdprHandler`'s constructor gained a
`CartSessionStore` parameter (`Bootstrap` wires it via the existing
`cart_session_store()` accessor). Tests: a new
`tests/Unit/Privacy/GdprHandlerTest.php` isolates the cart-session logic
with a fake-store double (mirrors `CartAbandonmentSweeperTest`'s pattern,
no WP/WC runtime needed); `tests/Integration/RecEngineGdprTest.php` gained
three cases against the real DB (export surfaces a row, erase deletes it,
erase matches via user_id when the email column is drifted).
`docs/DATA_MODEL_GDPR.md` updated in the same commit (table + prose no
longer say "not registered"). `docs/site/index.html` (merchant docs)
made no claim about WP Privacy export/erase scope that this touches —
left unchanged. `ci:strict` + `sg docker -c "composer run
test:integration"` both green.
Prior: 2026-07-13 (**PRO-1194 — GDPR-doc follow-up on the PRO-1341
re-audit's Info finding 2 DONE (docs only).** `docs/DATA_MODEL_GDPR.md`'s
scope note previously limited the whole document to "rec-engine personal
data only", so the PRO-1195 cart-session tracker (`smly_plus_cart_session`,
migration 009) went undocumented even though it stores local PII (email,
first/last name, cart contents) in the merchant's own WordPress DB. Scope
note widened + a new "local WordPress data" subsection added under Data
element inventory, code-derived (read `CartHookHandler`/
`CartAbandonmentSweeper`/`CartSessionStore` directly, not assumed): fields =
`cart_token`/`user_id`/`email`/`first_name`/`last_name`/`cart_content`
(product_id/variation_id/quantity per line)/`cart_updated`/
`reminder_enqueued_at`/`created_at`; purpose = abandoned-cart reminder email
via Smaily, independent of the rec-engine connection; retention = every row
deleted once `cart_updated` is older than ~24h (`DAY_IN_SECONDS`, filterable
via `smaily_connect_abandoned_cart_max_age_seconds`), whether or not a
reminder fired, on every 15-min sweep tick even while the feature is gated
off — plus immediate deletion on cart-empty, order completion, or a
guest→login session migration. Also recorded as fact: `GdprHandler`'s WP
Privacy exporter/eraser (`includes/Privacy/GdprHandler.php`) does **not**
cover this table — a subject-access/erasure request today neither surfaces
nor removes an in-flight cart-session row (mitigated by the ~24h auto-purge,
not eliminated). The merchant privacy-policy template (same doc, EN+ET
siblings) didn't mention abandoned-cart local data at all — added one
minimal "What data is used" bullet + one retention sentence to BOTH language
blocks (no restructuring), plus a template↔code fact-map row. No code
changed; `ci:strict` not run (docs-only). ~~Follow-up (not done here):
register a WP Privacy exporter/eraser for `smly_plus_cart_session`, or
explicitly decide the ~24h auto-purge is sufficient mitigation and record
that decision.~~ **RESOLVED PRO-1343 (2026-07-14, see above)** — an
exporter/eraser is now registered.
Prior:
**PRO-1341 — v3.7.0 release-gate delta security +
code-quality re-audit DONE, verdict PASS.** Scope = `aa86c9a..HEAD` (the
v3.6.1→now delta: the PRO-1195 abandoned-cart rewrite onto the namespaced
pipeline (biggest piece, +7115/-1100 lines total across 16 commits), the
disabled-workflow dropdown filter (PRO-1277/1334), the ProfilingConsent
fail-open hardening (PRO-1194), the uninstall.php `smly_profiling_*`/
`smly_rec_*` sweeps (PRO-1336/1337), `/events` route test-pinning (PRO-1258),
contract sync, new dev docs). Read the full diff file-by-file (clean-context
agent, no involvement writing the delta) against every high-risk surface the
task named: the new `smly_plus_cart_session` tracker's SQL (all `$wpdb->
prepare()`d, no raw interpolation of request input), the checkout-input
capture hooks (classic `checkout_update_order_review` + Store API
`cart_update_customer_from_request` — both sanitize+validate before storage,
no superglobal read directly), `ProfilingConsent`'s durable opt-out registry
(hashed-email keys, autoload=false, no email in any log line), `uninstall.php`
(both new LIKE-sweeps prepared+esc_like'd, new table correctly wired into the
drop list + EnvScrub + SchemaMigrationTest), and the two-flusher event-type
queue scoping (holds both directions, unit-pinned). Confirmed NOT touched:
REST route surface (grep for `register_rest_route(` across the whole delta
found zero new calls — EndpointRegistry's change is test-pinning only), the
`/relay` beacon, auth/nonce paths, crypto. **Verdict: PASS — 0 Critical/High/
Medium/Low, 2 Info** (both pre-existing/docs-scope, not delta regressions: a
dead `uninstall.php` `$legacy_options` entry that's actually a leftover
table-suffix string, harmless no-op; the new cart tracker's local PII isn't
mentioned in `DATA_MODEL_GDPR.md`, which is consistent with that doc's own
stated "rec-engine data only" scope note). Every F3-37/F3-44/F3-53 discipline
point named in CLAUDE.md was checked against the actual delta code, not just
assumed, and holds. Full report:
[`docs/audits/2026-07-13-SECURITY_QUALITY_RE_AUDIT_PRO1195_CART_REWRITE.md`](docs/audits/2026-07-13-SECURITY_QUALITY_RE_AUDIT_PRO1195_CART_REWRITE.md),
register row added to `docs/audits/INDEX.md`. This was a read-only analysis
pass (no code touched); `ci:strict`/PCP-against-ZIP/live-walk are separate
steps still needed before the v3.7.0 tag — STATUS already records green
per-commit gates for every commit in this delta individually (see the
PRO-1337/1336/1334/1277/1194/1258/1195/1197/1256/1250 entries below). Prior:
**PRO-1337 DONE — uninstall.php now sweeps
`smly_rec_*` (rec-engine connection state), closing the gap PRO-1336 flagged.**
Approved design: full irrecoverable local delete, no engine-side revoke call.
Three additions, all mirroring `uninstall.php`'s existing `smly_plus_*`
conventions rather than a new one: (1) a `LIKE 'smly_rec_%'` option sweep —
catches all 9 `Settings\RecEngineSettings` options (api_key, base_url, tenant
id/name, endpoints, config, connected, issued_at) plus
`NotificationManager::OPTION_DOWN_SINCE` (`smly_rec_health_down_since`, a
`smly_rec_*` option that lives outside RecEngineSettings) — prefix sweep
chosen over an explicit key list (mirrors `smly_plus_%`) so a future
`smly_rec_*` option needs no new uninstall.php line; (2) the Action Scheduler
purge gained an `OR hook LIKE 'smly_rec_%'` clause — the four rec-engine
recurring flush hooks (`smly_rec_flush_ingest/_customers/_orders/
_catalog_remove`, `Bootstrap.php`) were previously never unscheduled, so
they'd keep firing on a class that no longer exists after an uninstall; (3)
the custom-tables list (`smly_rec_event_queue`, `smly_rec_visitor`) was
**already correct pre-PRO-1337** — verified against the migrations, no
duplicate/change made there. `docs/ARCHITECTURE.md` §7 corrected in the same
commit (it already claimed "uninstall.php removes all of it" — now true,
with the engine-side-revoke note added: the api_key stays valid on the engine
until rotated/revoked in the engine admin, per-connection keys since engine
migration 0036 so no other store is affected, and a re-install needs a fresh
setup token). `tests/Unit/UninstallCleanupTest.php` extended with two new
source-level pins (reflection against `RecEngineSettings`/`NotificationManager`
option constants and the four Flusher/IngestQueue `FLUSH_HOOK` constants) —
`uninstall.php` itself is still never executed by the suite (destructive,
same rationale as PRO-1336). Gates: `ci:strict` exit=0 (phpcs 0 errors/phpstan
no errors/unit 563 tests incl. +2 new/lint/typecheck/vitest 236); integration
148 OK (`sg docker`, sandbox tenant `Smaily Connect test` snapshot/restored
cleanly, `smly_rec_*` unaffected by the suite run itself since uninstall.php
isn't executed). Prior:
**PRO-1338 DONE — merchant docs site
(`docs/site/index.html`) now documents the `[smaily_connect_newsletter_form]`
shortcode**, in both EN/ET. Added a "Shortcode" subsection under
Settings → Integrations (alongside the existing newsletter-block/CF7/Elementor
copy): what it renders, an attributes table (`success_url`, `failure_url`,
`show_name`, `autoresponder_id` — names/defaults read straight from
`Public_Base::smaily_shortcode_render()`'s `shortcode_atts()` call and the
`smaily-public-basic.php` partial), a copy-paste example, and the
theme-override note (`smaily/smaily-public-basic.php` via `locate_template()`).
Plus a one-line pointer bullet in the Step 5 wizard summary. **Limitation
documented, no code change:** unlike the CF7/Widget/Elementor/Gutenberg
dropdowns (PRO-1277/PRO-1334), `autoresponder_id` here is hand-typed with no
list to validate against — a workflow later disabled/deleted in Smaily leaves
the form rendering and submitting normally with no automation firing, and
nothing in WordPress flags it. Verified structurally (no browser available
here): Python `html.parser` tag-balance walk over the whole file reports zero
mismatches/unclosed tags; `data-lang="en"`/`"et"` block counts (121/119) are
unchanged from HEAD — this change added zero new `data-lang` blocks, only
content inside existing paired blocks. Docs-only change; `ci:strict` not run
(nothing else touched). Prior:
**PRO-1334 DONE — preserve-and-flag (PRO-1277)
extended to the classic Widget, Elementor widget, and Gutenberg block
autoresponder dropdowns.** Classic Widget (`includes/smaily-widget.class.php`)
mirrors CF7 exactly, reusing `Helper::is_autoresponder_unavailable()` unchanged:
a saved-but-now-disabled `autoresponder_id` gets a selected, labeled
"Workflow #N (disabled in Smaily)" option plus the same warning notice text
(reused verbatim — no new string needed there). Gutenberg block
(`blocks/newsletter-signup/src/edit.js`) does the equivalent purely
client-side: `autoresponders` (already the filtered enabled list from
`/smaily/v1/autoresponders`) plus the saved `autoresponderId` attribute are
enough to detect unavailability in the editor, so the REST endpoint
(`includes/smaily-api.class.php`) needed NO change — reuses the same two
msgids via `sprintf`/`__` from `@wordpress/i18n` (already flowed into the .pot,
confirmed: unlike the admin TS bundle, `wp i18n make-pot` parses this block's
plain `.js` directly, no esbuild-transpile workaround needed). Elementor
(`integrations/elementor/newsletter-widget.class.php`) got a **reduced,
non-dynamic fix, and here's why**: Elementor's SELECT `options` are a
per-widget-TYPE schema cached once via `Controls_Manager::get_element_stack()`
and shared across every instance/document — confirmed via Elementor's own
source + developer docs, including a filed core issue where reading a
widget's own raw settings during `register_controls()` is documented to
error ("widget doesn't exist on stage"). There is no reliable per-instance
hook to inject a flagged option there without either a sitewide postmeta scan
or client-side JS overrides of Elementor's own panel — disproportionate
machinery for a dropdown cosmetic gap. Separately, the CF7/Widget *mechanical*
silent-wipe risk doesn't actually apply to Elementor: its settings are a
persistent client-side model, not re-derived from the rendered `<select>`'s
value on save, so an untouched saved id survives a save regardless. Shipped
instead: one additional sentence appended (not replacing) the control's
existing, already-translated `description`, telling the merchant a missing
entry means "disabled in Smaily, your selection is kept" rather than "gone."
Tests: no new Helper logic was added (Widget/block both reuse PRO-1277's
already-tested `filter_enabled_autoresponders()` / `is_autoresponder_unavailable()`
unchanged), so no new unit tests; `LegacyHelperAutorespondersTest.php` still
covers the shared logic. One new PHP string (the Elementor description
addendum) added to `.pot`/`-et.po` (ET translated) via `bin/build-i18n.sh`;
the two reused strings' locations were merged in, translations intact
(spot-checked: msgstr count 523→524, empty-msgstr count unchanged at 1). No
merchant-docs-site change (re-verified: still doesn't document per-row
dropdown filtering, per PRO-1277's own conclusion). Gates: `ci:strict` exit=0
(phpcs 0 errors/phpstan no errors/unit 561 tests unchanged/lint/typecheck/
vitest 236 unchanged); `composer run build` — all blocks incl.
newsletter-signup compile clean; integration 148 OK (`sg docker`, sandbox
tenant `Smaily Connect test` snapshot/restored cleanly). **Human acceptance
needed (not verifiable here):** visually confirm the flagged option +
Estonian translation actually render in the WP Widgets screen, the Gutenberg
block inspector, and — for the Elementor description text — the Elementor
panel; none of the three has an Elementor instance available in this
environment to render against. Prior:
**PRO-1336 DONE — uninstall.php now removes the
PRO-1194 profiling-consent state.** The durable opt-out registry option
(`smly_profiling_optouts`, autoload=false, hashed-email keys) and its two
per-contact transients (`smly_profiling_<hash>` daily-TTL fresh cache,
`smly_profiling_stale_<hash>` no-expiry stale cache — both from
`ProfilingConsent`, commit 31a8c0d) were not covered by `uninstall.php`'s
existing cleanup: its LIKE-prefix sweep only matches `smly_plus_*`, and its
explicit legacy-options list is `smaily_connect_*`-scoped — neither shape
matched `smly_profiling_*`. Fix follows the file's own two established
conventions rather than inventing a third: the single known option key
(`smly_profiling_optouts`) joins the explicit `$legacy_options` array
(delete_option + the existing per-key cache-flush loop already iterates it);
the two per-contact, unbounded-count transients get a new LIKE-prefix sweep
(`_transient_smly_profiling_%` / `_transient_timeout_smly_profiling_%`),
mirroring the existing `smly_plus_%` sweep — the stale-cache prefix nests
inside the fresh-cache prefix so one pair of LIKEs catches both. No existing
test executes uninstall.php (it DROPs tables + bulk-deletes options, which
`tests/Integration/Support/EnvScrub.php`'s own docblock calls out as too
destructive to run inside the shared test process — that's why EnvScrub
exists as a non-destructive sibling instead of piggybacking on the real
file); added `tests/Unit/UninstallCleanupTest.php` instead, a cheap
source-level pin against `ProfilingConsent`'s actual constants (via
Reflection) so a future prefix rename or a stripped cleanup line fails
loudly without executing the destructive script. Gates: `ci:strict` exit=0
(phpcs 0 errors/phpstan no errors/unit 561 tests, +2 new/lint/typecheck/
vitest 236); integration 148 OK (`sg docker`, sandbox tenant `Smaily Connect
test` snapshot/restored cleanly — unaffected, this change doesn't touch
`smly_rec_*`). **Related finding, NOT fixed here (scope was PRO-1336 only):**
`uninstall.php`'s LIKE sweep also does not cover `smly_rec_*` (the rec-engine
connection: API key, tenant, endpoints map — `Settings\RecEngineSettings`),
despite `docs/ARCHITECTURE.md` §7 claiming uninstall "removes all of it
(options, tables, AS actions)" — that doc line is stale/inaccurate for the
options piece. Left as a follow-up (separate scope, pre-existing, not
introduced by this change) — worth its own Linear issue given it's a
real data-retention gap on uninstall. Prior:
**PRO-1194 fail-open GDPR window — hardened
(serve-stale-on-error + durable opt-out registry).** Design approved by Erkki;
implements options B+C from the `docs/DATA_MODEL_GDPR.md` fail-open review
(commit 47897a0). `ProfilingConsent` (`includes/Privacy/ProfilingConsent.php`)
now resolves a profiling decision through four layers instead of one: (1) the
existing fresh per-email transient (1-day TTL, unchanged); (2) a new **durable
opt-out registry** — a single `smly_profiling_optouts` option (autoload=false,
keyed by hashed email, opt-outs only) that a read error can never override,
cleared only by a later successful engine read-back showing opt-in; (3) a new
**stale cache** — a second per-email transient with no TTL, holding the last
successfully fetched answer, served on a read error instead of defaulting to
allowed; (4) true fail-open, now residual — only fires for a contact the
plugin has never resolved either way (never-seen + engine down). Every
successful read (and every WP-side `opt_out()`/`opt_in()`) writes all three
stores together via a new `remember()` helper. This is a privacy-POSITIVE-only
change — it never allows more profiling than before; it only closes cases
where the old code defaulted to "allowed" and now correctly denies. No
user-visible change (no merchant-docs-site edit — verified the site doesn't
describe the fail-open window). `docs/DATA_MODEL_GDPR.md`'s fail-open review
section is updated in the same commit: status flipped from "DRAFT" to
"IMPLEMENTED 2026-07-13", the B/C alternatives-table rows marked implemented,
and a new "Implemented behavior" matrix documents the four layers. Tests:
`tests/Unit/Privacy/ProfilingConsentTest.php` gained 4 new cases (stale served
on error, durable opt-out wins over an error, a successful opt-in read clears
the durable entry, an opt-out read persists it durably) plus stub updates to
4 existing cases (`get_option`/`update_option` now touched by every
success-path write). No integration test added — `ProfilingConsent` had none
to extend. Gates: `ci:strict` exit=0 (phpcs 0 errors/phpstan no errors/unit
559 tests incl. ProfilingConsentTest's 10 methods expanding to 16 tests via
the `is_allowed` data provider (24 assertions)/lint/typecheck/vitest 236);
integration 148 OK (`sg docker`, sandbox tenant `Smaily Connect test`
snapshot/restored cleanly). ~~PRO-1194 overall **stays OPEN** — legal sign-off (entity name, URL,
lawful-basis framing) still pending; only the fail-open hardening sub-item is
done.~~ **RESOLVED 2026-07-14 — see top of file.** Prior:
**PRO-1277 DONE — legacy Autoresponder dropdown
(CF7 / Elementor / Gutenberg newsletter block) stops offering `is_enabled=false`
Smaily workflows.** `Helper::get_autoresponders_list()` (`workflows.php?
trigger_type=form_submitted`, `includes/smaily-helper.class.php`) shapes rows
through the new `Helper::filter_enabled_autoresponders()`, which drops any row
whose `is_enabled` coerces false (`FILTER_VALIDATE_BOOLEAN`, so a bool/int/string
"false"/"0" wire value is all read the same way — a row missing the key is kept,
since we can't classify it). This is the single fetch point behind all four
consumers (CF7 admin tab, the classic Widget, the Elementor widget, the Gutenberg
block's `/smaily/v1/autoresponders` REST route) so the fix applies uniformly.
Preserve-and-flag (same pattern as Magento's PRO-1268): in the CF7 admin tab, if
a form's saved `autoresponder_id` is no longer in the filtered enabled list
(`Helper::is_autoresponder_unavailable()`), the dropdown keeps it as a selectable,
flagged option ("Workflow #N (disabled in Smaily)") plus a warning notice, instead
of silently reverting the binding to "No autoresponder" on the next save. Only CF7
got the flag treatment — the classic Widget has the identical native-`<select>`
shape and risk but wasn't touched (follow-up below); Elementor/Gutenberg use their
own control rendering and weren't in scope. Tests: `tests/Unit/
LegacyHelperAutorespondersTest.php` (16 cases: bool/int/string `is_enabled` wire
shapes, junk/incomplete rows, missing-key kept, the 4 preserve-and-flag states).
`languages/smaily-connect.pot` + `-et.po` gained the two new CF7-partial strings
(ET translated). No merchant-docs-site change — the site doesn't document
per-row dropdown filtering or a disabled-workflow marker. Gates: `ci:strict`
exit=0 (phpcs/phpstan/unit 555/lint/typecheck/vitest 236); integration 148 OK
(`sg docker`, sandbox tenant `Smaily Connect test` snapshot/restored cleanly).
Follow-up filed: extend preserve-and-flag to the classic Widget
(`includes/smaily-widget.class.php`) — same helper, same bug shape, not done
here. Prior:
**PRO-1194 retention finalized —
`docs/DATA_MODEL_GDPR.md` merchant privacy-policy template.** Engine team answered
the retention question + Erkki decided the orders/customers wording (2026-07-12):
browse events (incl. `smaily_visitor_token` rows) and visitor-token↔customer
bindings — 90-day TTL from creation, engine daily `cleanup-expired-data` cron
hard-deletes; order & customer ingest rows — **no fixed calendar period**, retained
for the duration of the merchant relationship, individual control is the Art 17
erase (`DELETE /api/v1/customer/{email}`), natural upper bound is merchant
offboarding (engine-side tenant purge — tracked as an engine-backlog follow-up, not
yet built); recommendations 730 days from issue (pending never aged out early),
rec_attribution 730 days, email_events 365 days, decision_log 30 days
(engine-internal, not a customer-facing element in the inventory). The
`[CONFIRM WITH ENGINE TEAM]` retention placeholder in BOTH template languages
(EN+ET, content-identical siblings) is replaced with concrete plain-language
retention text; the template↔code fact map gained a row and the open-placeholders
list marks item 3 RESOLVED. Other placeholders (Smaily legal entity name, Smaily
privacy-policy URL, merchant-legal-review caveat, lawful-basis framing) untouched;
the fail-open GDPR window review section untouched (it had no retention
cross-reference); nothing published to the merchant docs site. **PRO-1194 stays
OPEN** — legal sign-off (entity name, URL, lawful-basis framing) still pending.
Docs-only, no code change. Prior:
**Contract re-synced byte-identical (engine `945b7ad`, md5
`3dbe029b…`) — PRO-1279.** Doc-only delta since our `2dec424` sync (v1.4.0 → v1.4.1,
PATCH bump): §3 `tags` example gains `"product_id": "7620134"`, and the identity bullet
now states cross-variant grouping by `tags.product_id` is **live** (engine PRO-1227,
was "future" in v1.3.0); §3 also gains an explicit Magento-only carve-out (Magento's
catalog `sku` field IS its platform-canonical key — does not apply to Shopify/Woo).
CC-8 conformance verified: no wire-shape change for Woo — `CatalogPayloadBuilder::tags()`
already emits `tags.product_id` via `SkuResolver::product_group_id()` since PRO-1224/
PRO-1230, pinned by `CatalogPayloadBuilderTest` and mirrored in the mock router. No code
change, no live-walk needed (prose clarify + an already-shipped field's example gains
a value). Gates: `bin/check-contract-staleness.sh` green. Prior:
**PRO-1258 DONE — `EndpointRegistry::expected_routes()`
now lists the `/events` triple** (GET `/events`, GET `/events/detail`, POST
`/events/retry`) — the PRO-1197 follow-up below. The route surface is confirmed
against every `register_rest_route()` call in `includes/REST/`: 16
method+path pairs total, matching `docs/API.md` §1 exactly (the legacy
`smaily/*` namespace in `smaily-api.class.php` is out of this list's scope on
purpose). `RestRouteRegistrationTest` now pins all 16 against the live
`rest_get_server()`, and `EndpointRegistryTest` asserts every pair plus
`assertCount(16)` so a route can't silently drop out again. Code-only test-coverage
fix, no wire/behavior change. Gates: ci:strict exit=0 + integration suite green
via the wrapper. Prior:
**PRO-1195 DONE — abandoned cart REWRITTEN onto the
namespaced pipeline; legacy pass RETIRED. Landed on main, UNRELEASED** (rides a
later cut AFTER the 2026-07-14 MiuMjau window — no version bump, readme.txt
untouched). Erkki-approved design: `CartHookHandler` (WC cart hooks; **guest
carts included** — session-token rows; identity = logged-in user / session
billing email / checkout-entered email via classic
`checkout_update_order_review` + Store API `cart_update_customer_from_request`;
a cart syncs only once an email is known) → new `smly_plus_cart_session`
tracker (migration 009; own scalar JSON `[{product_id, variation_id,
quantity}]`, never `serialize(get_cart())` — the F3-53 poison class is
structurally gone) → `CartAbandonmentSweeper` on the EXISTING 15-min
`smly_plus_abandoned_cart` AS tick (same cutoff option; F3-37 backlog guard
carried over, same filter name; expiry/prune housekeeping runs even while
gated) → `automation.abandoned_cart` rows in the Smaily EventQueue
(`pending()` grew only/exclude event-type scoping; the main Flusher excludes
the cart type) → new `CartFlusher` on its own AS action
`smly_plus_flush_cart_events` (60 s): F3-54 router-first → legacy
`autoresponder_id` fallback (force_opt_in=false) → observable terminal skip;
a non-101 fallback body code is terminal FAILED (Event-Log-retryable, never an
eternal loop); F3-44 exchange stored per row (never the Authorization header);
the Events retry endpoint kicks the cart flush hook too. Wire fields keep
EXACT legacy template parity (`is_abandoned_cart`, store/names, prefilled
`product_<field>_1..10`, `over_10_products`); language via
ContactLanguageResolver only (`for_user` + new `for_guest()`), omit-on-empty.
**Upgrade continuity (definition of done) proven:** the new code reads the
SAME options (normalized status incl. the carried-over autoresponder_id,
cutoff, fields — zero reconfiguration); one-time READ-ONLY `LegacyCartDrain`
on `Activation::run` (stamp `smly_plus_cart_legacy_drained`) migrates
`mail_sent IS NULL` legacy rows with their ORIGINAL `cart_updated` (recent →
reminds via the new pipeline; stale → F3-37 expiry without emailing; poison
rows logged+skipped with a per-row Throwable backstop; schedules NOTHING per
F3-53); the legacy table is NOT dropped (rollback-safe; drop = a later
one-way door). Retirement: legacy `Cart` tracker + the Cron abandoned-cart
add_actions deregistered (methods kept for the upstream diff) so a stray
surviving legacy WP-Cron event finds nothing to fire; Bootstrap's tick no
longer bridges the legacy hook names. Tests: +36 unit
(CartFlusher/CartAbandonmentSweeper/CartPayloadBuilder/CartHookHandler +
EventQueue scoping + main-Flusher exclusion); integration reworked — new
`CartPipelineTest` (logged-in E2E to the mocked Smaily transport incl. F3-44
exchange asserts, guest checkout-email capture, legacy-autoresponder fallback
carry-over, backlog guard, order-clears, Bootstrap hook registration) + new
`LegacyCartDrainTest` (both F3-53 poison classes, read-only + one-time +
original-timestamp semantics, drained-recent-reminds vs stale-expires);
`AbandonedCartGuardTest` retired WITH the pass it drove (its bug classes
re-pinned against the new code), `AbandonedCartSettingsSeamTest` re-pointed at
the new consumers, `LegacyCronScheduleTest` extended (cart callbacks
uninvocable). Gates: **ci:strict exit=0** (unit 539, vitest 236, PHPCS 0
errors, PHPStan clean) + **integration FULL OK (148 tests, 756 assertions)**
via the wrapper (sandbox connection auto-restored, tenant verified
'Smaily Connect test'). NO live Smaily/engine traffic. Merchant docs site
UNCHANGED — behavioral parity; every abandoned-cart statement on it stays true
(guest coverage extends, contradicts nothing). Docs same commit: DECISIONS
PRO-1195 (+ F3-37/F3-53/F3-54 pointer notes), CLAUDE.md coexistence map +
scar-note rewrite, ARCHITECTURE/API dev-doc tables refreshed (new AS jobs +
table + retired hook pair), BACKLOG row closed. Prior:
**PRO-1197 — developer docs written: `docs/ARCHITECTURE.md`,
`docs/DEVELOPER.md`, `docs/API.md`** (docs-only). The three long-TODO developer-facing
docs now exist, written from the actual repo (EndpointRegistry route surface incl. the
`/events` triple + the public `/relay` defense layers; all 11 `smaily_connect_*` filters
enumerated from code; the `window.smailyConnectBeacon` boot shape from
`StorefrontBeacon::beacon_config()`/`page_context()`; the AS job table from
`Bootstrap::register_action_scheduler_jobs()`; custom tables from `migrations/`).
They LINK to the deep docs (contract, DATA_MODEL_GDPR, DECISIONS, CLAUDE.md) instead
of duplicating them. `FAQ.md`/`TROUBLESHOOTING.md` stays **deliberately deferred**
until pilot support traffic supplies real symptom→cause→fix questions (recorded in
INDEX.md; the PRO-1197 issue stays open for that part). docs/INDEX.md rows moved
TODO→Written in the same commit. Gate: ci:strict as the docs-only sanity gate.
Noticed, not fixed at the time: `EndpointRegistry::expected_routes()` did not list
the three `/events` routes the registry registers — the route-registration test
under-covered them (FIXED as PRO-1258, see the entry above). Prior:
**PRO-1256 DONE — shared `smly_rec_*` snapshot/restore
guard + `--restore-only`** (dev tooling, follow-up to PRO-1240). The guard logic
moved out of `bin/run-integration-tests.sh` into `bin/lib-smly-snapshot.sh` —
sourced by the wrapper (NO behavior change: EXIT-trap restore,
fixture/production/empty never clobbers a good snapshot, secret-safe STDIN
restore, tenant_name verification with loud MiuMjau/fixture warnings, guard
problems never fail the run) and also executable (`snapshot`/`restore`
subcommands, always exit 0; the pre/post decision now persists via a
pending-decision file so it survives across processes). Walk scripts opt in
via the Node wrapper `bin/lib-smly-snapshot.cjs` — `guardSmlyRec()` snapshots
up front and restores on process exit (crash/SIGINT included); wired into
`bin/walk-3.1.cjs`, the ONLY existing walk that writes/deletes `smly_rec_*`
connection options (the others only read the connection or truncate the
queue table — decision recorded in CLAUDE.md; any future connection-writing
walk must call it). New `bash bin/run-integration-tests.sh --restore-only`
restores the dev connection from the durable snapshot without running the
suite (non-secret output only; exit 3 when no usable snapshot). Proof:
integration suite via the wrapper **OK (146 tests, 721 assertions)** with
restore verified `tenant_name='Smaily Connect test'`; `--restore-only`
demonstrated (real restore, 9 options, tenant verified; exit-3 path with the
snapshot absent); standalone `snapshot`→`restore` round-trip green; NO
live-walk run (host-side tooling only). ci:strict exit=0. Docs same commit:
CLAUDE.md (filtered-runs + live-walk + TENANT-scoped notes now point at the
shared lib + `--restore-only`), LESSONS §2.17 addendum. Prior:
**PRO-1194 DRAFT DELIVERED — merchant privacy-policy
template (EN+ET) + fail-open GDPR window review, in `docs/DATA_MODEL_GDPR.md`**
(docs-only; the issue stays OPEN for Erkki/legal sign-off — nothing published to
any user-visible surface, merchant docs site untouched). Two new sections: (1) a
clearly-marked DRAFT privacy-policy template block, EN + ET siblings, written
from verified plugin behavior (profiling = purchase history + consented browse;
customer/order payload fields; F3-49 visitor-token-only browse identity; F3-46
consent-ungated attribution cookies `smaily_rec_id`/`smaily_rec_ctx` 30 d +
`smaily_rec_uid` 365 d defaults; My Account opt-out via `ProfilingConsentAccount`;
WP Privacy export/erase via `GdprHandler`; ≤24 h opt-out propagation from the
`ProfilingConsent` daily-TTL cache), with a template↔code fact map and explicit
placeholders — Smaily legal entity name, Smaily privacy-policy URL, engine-side
retention period (`[CONFIRM WITH ENGINE TEAM]`), and the lawful-basis framing
(legitimate interest + Art 21 opt-out per F3-31) all need Erkki/legal
confirmation; (2) a fail-open GDPR window decision review (behavior restated
from `ProfilingConsent.php`, risk analysis incl. the transient-eviction
sharpening F3-31 didn't cover, 5 alternatives) — **recommendation: keep the
F3-31 fail-open default but harden with serve-stale-on-error + durably persisted
known opt-outs (options B+C, follow-up sub-PR)**; no code/behavior change in
this pass. Gate: ci:strict as the docs-only sanity gate. Prior:
**PRO-1250 DONE — contract-staleness CI guard**
(Decision A on PRO-1247): new standalone workflow
`.github/workflows/contract-staleness.yml` (push to main / PR / daily 05:17 UTC
schedule — the schedule is the real guard — / dispatch) runs
`bin/check-contract-staleness.sh`, which md5-compares our vendored
`docs/RECENGINE_API_CONTRACT.md` against the engine repo's main
(`erkkimarkus/smaily-recommendations`, PRIVATE) and fails "CONTRACT COPY STALE —
sync from engine@<sha>" (exit 1) with local+engine md5 + the CC-8 instruction;
a missing/expired secret fails with a DISTINCT "CANNOT CHECK" (exit 2). Script
verified locally in all modes: in-sync via local checkout arg, no-arg fallback,
and the real GitHub-API CI path (all OK, md5 `b285ded8…`, engine commit
`2dec424`); simulated-stale temp copy → exit 1 with the full message;
no-source → exit 2. **Pending from Erkki: mint a fine-grained PAT
(contents:read on `smaily-recommendations` only) and add it as repo secret
`ENGINE_CONTRACT_READ_TOKEN`** — until then the scheduled run is red with
"CANNOT CHECK" (deliberately loud, not silent). Per-bump contract-sync issues
are RETIRED for this repo (CLAUDE.md CC-8 note updated); the sync discipline
itself (byte-identical + mock + code follow-through) is unchanged. Prior:
**v3.6.1 RELEASED** — patch release per Erkki's call
(PRO-1241 is a bugfix). Full GH release on the fork, tag `v3.6.1`, target main,
**Latest** (non-prerelease); asset `smaily-connect.zip` is the locally-built verified
ZIP (clean build-hash `aa86c9a`, 1 076 060 B — re-verified un-clobbered after the
expected harmless `release.yml` failure). Contents since 3.6.0: PRO-1241 gross
(tax-inclusive) order amounts per contract v1.4.0 §5 + the PRO-1240 dev-only
snapshot tooling (`bin/`, not shipped). Changelog + upgrade notice state that
already-connected stores' HISTORICAL order rows are corrected by the
engine-coordinated re-sync (rides PRO-1233, ~2026-07-14) — nothing merchant-side;
new orders are gross immediately on update. Gates (3.6.1 release-gate row in
`docs/audits/INDEX.md`): PCP on the built ZIP clean except the intentional
`Update URI` (F3-35); ci:strict exit=0 (unit 503, vitest 236); integration not
re-run (delta past the integration-green `b249887` is version/readme/`.pot`-header
only); no security re-audit (below policy threshold — no new
REST/auth/crypto/SQL/PII/external-HTTP surface; judgement recorded in the row).
**Gate-time incident, recovered:** a container-side `rm -rf` on the bind-mounted
`plugins/smaily-connect` PCP path wiped the host working tree incl. `.git`;
recovered by a full re-clone from `origin/main` (everything was pushed; only the
local version-bump commit needed recreating) + full rebuild — CLAUDE.md PCP
section now carries the never-touch-the-mount rule. Prior:
**PRO-1241 DONE — all order money fields GROSS (tax-inclusive)
per contract v1.4.0 §5.** MiuMjau prod verification (PRO-1202)
showed line items serialized ex-tax (bare `get_total()`) under a gross `total_amount` —
per-SKU revenue understated ~24% (median `unit_price/catalog.price` ≈ 1/1.24, Estonian
VAT). Contract synced byte-identical to **v1.4.0** (engine `2dec424`, md5 `b285ded8…`,
commit `434ffee`): §5 "Amount semantics" (all order money gross), §6 `plugin_magento`
source constant (no code change for us) + profiling opt-out enforcement documented (our
F3-49 sender-side omission already conforms). Code (`150e04e`): `OrderPayloadBuilder` —
the single money chokepoint (live hook, flusher retries, order backfill all build through
it at send time) — now wires `line_total = get_total() + get_total_tax()`, `unit_price =
gross line ÷ qty` (post-discount basis per §5, no longer `subtotal/qty`), line
`discount_amount` = the gross subtotal-vs-total delta, order `discount_amount =
get_total_discount(false)`; `total_amount` unchanged (already gross incl. shipping). No
wire-SHAPE change, values change basis. §5 sender invariant `Σ line_total + shipping ≈
total_amount` pinned in unit tests (taxed multi-line + discounted, zero-tax, rounding
edge, gross-discount arg pin) and integration (REAL WC tax engine: 24% rate + fixed-cart
coupon + taxed shipping; zero-tax case). Mock deliberately does NOT reject on tax basis
(live doesn't either — §5 invariant is monitoring, not a 4xx; documented at the route).
Docs: DECISIONS PRO-1241 (supersedes F3-22's amount serialization, banner added),
CLAUDE.md gross-money note. Gates: **ci:strict exit=0**; **integration OK (146 tests,
721 assertions, +2)** via the PRO-1240 auto-snapshot path (restore verified
`tenant_name='Smaily Connect test'`). **Live-walk `bin/walk-pro1241-gross-orders.cjs`
LIVE OK 9/9** against the sandbox: gross line_total/unit_price/discount on the wire,
invariant exact (55.80 + 6.20 = 62.00), engine accepted (processed=1, zero errors[]),
F3-44 stored exchange confirms; residue = ONE sandbox order row (external_order_id 2852)
+ auto-created customer `pro1241-gross@example.com`; store-side fully cleaned (order via
`wc_get_order()->delete(true)`, tax rate/options restored). **Deployment dependency:**
the one-time historical MiuMjau order re-sync (net→gross correction) rides the
engine-side PRO-1233 purge + re-backfill window (~2026-07-14), engine-coordinated —
release this (human-gated, separate step) before the MiuMjau flip so live+backfill
orders go gross. Prior: **PRO-1240 DONE — automatic `smly_rec_*` snapshot/restore
around integration runs.** `bin/run-integration-tests.sh` (= `composer run
test:integration`) now snapshots the DEV site's `smly_rec_*` options to
`~/.local/state/smaily-connect/smly_rec_snapshot.json` (mode 600, outside the repo,
`.prev.json` rotation) before the suite and — even on suite failure, via an EXIT
trap — restores them secret-safely afterwards (JSON over STDIN into `docker exec -i …
wp eval-file bin/restore-smly-rec-options.php`, never on a command line) and verifies
the restored `tenant_name` (loud warning on `MiuMjau`/fixture). A fixture/empty state
never overwrites a good snapshot; an intentionally disconnected dev site is not
auto-reconnected. Closes the F3-53 / LESSONS §2.17 memory-based-discipline gap that
twice killed the dev sandbox connection. Proof run: integration 144 OK (697
assertions) via the new path, restore verified `tenant_name='Smaily Connect test'`;
ci:strict exit=0. Docs updated same commit: CLAUDE.md (live-walk + filtered-runs +
TENANT-scoped notes), LESSONS §2.17 mechanical-guard addendum. Prior:
**v3.6.0 RELEASED.** Full GH release on the fork, tag
`v3.6.0`, target main, **Latest** (non-prerelease); asset `smaily-connect.zip` is the
locally-built verified ZIP (clean build-hash `f8903ce`, 1 075 310 B — re-verified
byte-identical AFTER `release.yml` fired on publish and failed harmlessly as expected
(no wp-cli in the runner); the asset was NOT clobbered, per the CLAUDE.md release
note). Contents: PRO-1224 canonical `woo-<id>` identity + `tags.product_id`, PRO-1230
§3b `catalog/remove` on hard delete, merchant docs site + in-plugin Documentation
links. Gates were green at prep (3.6.0 release-gate row in `docs/audits/INDEX.md`):
delta security+quality re-audit **0 Crit/High/Med**, PCP on the built ZIP clean except
the intentional `Update URI` (F3-35), ci:strict exit=0, integration 144 OK,
PRO-1224/1230 live-walk LIVE OK 20/20. **Deployment dependency:** MiuMjau's update to
3.6.0 must ride the coordinated engine-side purge + full re-backfill (engine PRO-1233,
week of 2026-07-14) — do not update the pilot store before the engine flip. Prior same
day: **Contract re-synced byte-identical (engine `2ff57e8`, md5
`1777746b…`) — PRO-1234.** Doc-only delta since our `8a0749f` sync, two engine commits:
`2ff57e8` (§3 identity clarify: a merchant-entered SKU, if ever sent, goes in
`tags.merchant_sku` — NEVER in `external_id`, which carries the platform variant id and
drives collision detection; engine consumes `tags.merchant_sku` nowhere today) and
`6b225fb` (setup-exchange endpoints map now carries `ingest_catalog_remove`). CC-8
conformance verified: plugin already conforms — merchant SKU appears on NO rec-engine
wire path (PRO-1224 dropped it entirely; `CatalogPayloadBuilder` `external_id` = raw
`get_id()`), and `Client::catalog_remove()` already resolves
`endpoints[ingest_catalog_remove]` with the absolute-path fallback (the fallback is now
for pre-`6b225fb` stored maps, no longer load-bearing for fresh connections). Mock moved
in the same sync: setup-exchange map now serves `ingest_catalog_remove` (14 keys); stale
"speculative key" Client docblock fixed. **No wire-SHAPE change → no live-walk needed**
(prose clarify + map addition already exercised by ClientTest both ways). Gates:
ci:strict exit=0; integration not run (doc + mock-map + comment delta only). Prior:
**v3.6.0 RELEASE PREPARED** (published later the same day — see
the head of this note; the release is the prerequisite for the MiuMjau key-migration,
engine PRO-1233, week of 2026-07-14). Version bumped 3.5.0→3.6.0 in all pinned spots
(`e1d20e5`); changelog + upgrade notice call out that already-connected stores need the
coordinated engine-side purge + full re-backfill (keys change to `woo-<id>`) BEFORE the
pilot flip. Re-audit policy TRIGGERED (REST retry-kick change + new outbound
`Client::catalog_remove()` + new `before_delete_post` surface, ~3.4k lines) → delta
security + code-quality re-audit run by a clean-context agent: **0 Crit/High/Med, 1 Low
(cross-flusher tombstone ordering race, accepted — follow-up: ask the engine whether a
plain upsert clears a tombstone's `recommendable=false`), 4 Info accepted** —
`docs/audits/2026-07-10-SECURITY_QUALITY_RE_AUDIT_PRO1224_PRO1230.md` + two INDEX rows.
Gates: ci:strict exit=0 (unit 500, vitest 236); integration not re-run (delta past the
integration-green feature commits is version/readme/i18n-refs only; sandbox connection
untouched). Full local build sequence run (admin+client JS, blocks, `bin/build-i18n.sh`
— new "Documentation" string now in the `.mo` — prod-vendor package, dev vendor
restored). **PCP on the built ZIP: clean except the intentional `Update URI`** (one
gate-time fix: upgrade notice shortened under the 300-char limit). ZIP verified: clean
build-hash `f8903ce`, v3.6.0, required present, dev artifacts absent, ~1.07 MB —
`smaily-connect.zip` at the repo root, release notes drafted (scratchpad
`release-notes-3.6.0.md`). The `gh release create` / tag followed the same day —
RELEASED, see the head of this note.
Prior: **PRO-1224 + PRO-1230 LIVE-WALK DONE against the "Smaily
Connect test" sandbox** — `bin/walk-pro1224-1230.cjs`, LIVE OK, 20 checks. Proven on the
REAL engine: catalog rows key `sku=woo-<id>` + `tags.product_id` = RAW canonical parent
id (simple AND variable — variation rows key `woo-<variation_id>`, group to the parent);
a product WITH a merchant WC SKU still keys `woo-<id>` and the merchant SKU appears
NOWHERE in any wire payload; an order's `items[]` join on the same `woo-<id>` keys —
all accepted (processed, no errors[]; F3-44 exchanges stored). §3b live proof
(PRO-1230): trash enqueues ONLY the soft in_stock=false row (zero `catalog.remove`);
a hard-deleted PARENT flushes ONE `catalog/remove` → live engine `outcome=removed`
(variable parent: removed_products=1, rows_tombstoned=2; simple: removed_products=1)
— a REAL removal, not a not_found, because the §3b match hit the walk's own freshly
synced `tags.product_id`. Dev wp-env re-connected to the sandbox via a fresh setup
token (secret-safe STDIN exchange; token consumed + file deleted); `smly_rec_*`
snapshot saved host-side for post-suite restore (LESSONS §2.17). Still pending: the
one-time engine-side purge + full re-backfill of already-synced stores (coordinate
with the engine before the pilot flip). Prior: **PRO-1230 DONE — hard-delete → §3b `catalog/remove`;
landed on main, UNRELEASED.** A permanently deleted PARENT product (incl. purge-from-
trash — `before_delete_post` fires for both) now enqueues ONE `catalog.remove` row via
`CatalogHookHandler::on_hard_delete_product` (Bootstrap rebind; `wp_trash_post` keeps
the F3-40 in_stock=false soft path untouched). Payload = the RAW un-prefixed CANONICAL
parent id (`SkuResolver::product_group_id()` = `tags.product_id`; engine confirmed
2026-07-10 the §3b match is that exact string — NOT `woo-<id>`). Routing: single
VARIATION delete keeps the per-SKU soft path (§3b would tombstone surviving siblings);
translation delete re-syncs the canonical (P4); auto-draft GC skipped; per-variation
soft rows from WC's cascade delete are pre-claimed into the one remove. New
`CatalogRemoveFlusher` (own AS hook `smly_rec_flush_catalog_remove`, 60s tick, added to
the Event-Log retry kick list) drains the new `catalog.remove` event type from the
shared queue — §3b is NOT D6 ({ok, removed_products, rows_tombstoned, not_found}, no
errors[]), so `AbstractD6Flusher` grew a protected `apply_response()` seam; on 2xx every
row is SENT with the per-row outcome (`removed`/`not_found` — a not_found is contract-
success, never a retry) stored per F3-44. `Client::catalog_remove()` resolves
`endpoints[ingest_catalog_remove]` w/ fallback `PATH_INGEST_CATALOG_REMOVE` (the v1.3.0
map doesn't carry the key — fallback is load-bearing). Mock moved in the same pass
(CC-8): §3b route w/ wrapper validation + exact-string `tags.product_id` matching.
Docs: DECISIONS PRO-1230 (+ F3-40 gap-closed note), CLAUDE.md trash/hard-delete note
rewritten; merchant docs site unchanged (background ingest detail, no user-visible
behavior change). Gates: **ci:strict exit=0** + **integration OK** (`sg docker`; new
E2E: hard-delete→§3b wire w/ tombstone match, trash-no-remove + purge-removes,
variation-delete soft path, variable-parent family remove). The §3b live-walk is DONE
(2026-07-10, see the head of this note); pre-PRO-1224 rows lack `tags.product_id` →
§3b not_found until the coordinated purge + re-backfill. Prior: **PRO-1224 CORE DONE —
canonical `woo-<id>` key everywhere +
`tags.product_id`; landed on main, UNRELEASED.** `Support\SkuResolver::resolve()` now ALWAYS
emits `woo-<canonical_id>` (the platform id; prefix `woo-`, not `wc-`) and NEVER the merchant
WC SKU field — reversing F3-36's "real SKU else `wc-{id}`" (the resolver PATTERN — one
chokepoint for catalog+order+browse, canonicalization, never-drop deleted-line fallback now
`woo-oi-<id>` — is unchanged). `CatalogPayloadBuilder` now emits `tags.product_id` = the RAW
canonical PARENT id (`SkuResolver::product_group_id()`) — grouping (PRO-1227) + removal
(§3b/PRO-1230) key; RAW, not `woo-`-prefixed, for **Shopify parity** (`tags.product_id =
product.id`) + the §3b example `["7620134"]` (caught by reading the shipped Shopify code +
contract, NOT PRO-1230's looser `woo-<product_id>` prose — LESSONS §2.20). Merchant SKU
**dropped entirely** (engine answer PRO-1225: consumed nowhere; never in `external_id` —
that's the raw platform id + collision key). Docs: F3-36 SUPERSEDED banner + full PRO-1224
entry in DECISIONS; CLAUDE.md SkuResolver note rewritten; LESSONS §2.20. Mock moved to the new
shape (records `tags`; scenario triggers re-keyed sku→`event_id` since `sku` is no longer
test-controllable). Gates: **ci:strict exit=0** (PHPCS clean, PHPStan OK, unit 483, tsc,
vitest 236) + **integration 140 OK** (`sg docker`). The PRO-1224 **live-walk** is DONE
(2026-07-10, see the head of this note); still NOT done: the **one-time engine-side purge +
full re-backfill** of already-synced stores (every key changes `wc-<id>`→`woo-<id>`) — must be
coordinated with the engine before the pilot flip. **PRO-1230** (hard-delete → §3b) now
unblocked on the contract + `tags.product_id`. `external_id` decision resolved (omit merchant
SKU). Prior: **Contract synced to v1.3.0 (engine `8a0749f`, md5 `1886669e…`)** — additive MINOR
(PRO-1229/1228): §3b `catalog/remove`, sharpened `sku` identity rule, `tags.product_id`,
soft-removal lifecycle. Prior: **Merchant documentation site + in-plugin docs links —
landed on main, UNRELEASED.** New `docs/site/index.html`: a single self-contained
**bilingual (EN/ET)** HTML page (7 sections — Overview, Getting started/wizard,
Settings, Importing, Error messages, FAQ, Data & privacy) built to look and work like
the Shopify docs at `connect.smaily.com/docs`; content written from real plugin
behavior (no Shopify-isms — no OAuth prompts, no 60-day order window, WP Consent API
companion, Action Scheduler, WP Privacy tools). No build step, no external deps; hosted
separately, **live at https://smaily.com/connect-woo/**, excluded from the ZIP via
`.zipignore`. `docs/INSTALL.md` → thin pointer; CLAUDE.md/INDEX.md carry the keep-current
rule (update the site in BOTH languages in the same commit user-visible behavior
changes). Plugin now links to the docs from the **wizard + Settings screens** (a visible
"Documentation" link at the top of `admin/wizard.php`'s mount — help material one click
away on install) and the **Plugins page** (action link + row meta); every UI link
resolves through `Constants::docs_url()` (const `DOCS_URL`, filter
`smaily_connect_docs_url`) — one line to change when Smaily docs move to
`connect.smaily.com` (the long-term plan: each plugin its own home there). New string
"Documentation"/"Dokumentatsioon" in `.pot`+`-et.po` (`.mo`/`.json` regenerate at
package time). Commits `e57bcea` (site+doc updates), `df8a9c0` (in-plugin links),
`5db99cd` (CLAUDE note). Gates: **ci:strict exit=0** (PHPCS 0 errors, PHPStan OK, unit
480, vitest 236); PHPCS clean on the 3 touched PHP files. Integration NOT run (wp-env
down; change touches no ingest/queue/REST data path the suite covers — admin-UI-only,
ci:strict is the relevant gate). No release cut. Prior: **F3-55 — backfill progress:
users WALKED vs contacts SYNCED;
v3.5.0 RELEASED.** Prike: "contact sync shows 30k contacts going to Smaily, we have 16k
opt-ins" — the WIRE was correct (F3-48 audience filter POSTs only the mode's audience),
but `total_count=count_users()`, `processed_count` counts rows walked, and the UI
labelled that walk count "contacts synced" (`Step2Subscribers` + `Step6Done`). Fix
(`5104950`): migration 008 adds cumulative `synced_count` (audience members handled =
POSTed + already-fresh; the walk keeps driving percent/ETA — an audience-based
denominator would freeze through opted-out ID ranges); `ContactAudience::
count_audience()` = mode-aware SQL count NEXT TO `should_sync_user()`, integration test
pins the two halves agree in every mode; `/backfill/status` carries `synced` +
`audience_estimate` (contacts only, estimate only on non-running polls); UI copy —
pre-start "about N of them will be synced to Smaily as contacts" (only when the mode
narrows), running "Checked X of Y users — Z contacts synced", done "Done — Z contacts
synced (X users checked)"; et translations, i18n rebuilt (build-i18n ×2, admin-bundle
JSON verified). Engine backfills untouched. Also STABILIZED the recurring
`EngineAutomationsSection` dropdown vitest flake (`f92e4ef` — await the option, not the
select; failed 2× today under loaded parallel runs). Gates: ci:strict exit=0 (unit 480,
vitest 236); integration FULL 139/674 (+2); connection snapshot/restored. **v3.5.0
RELEASED** per recipe (build-hash `ea5bce0`, ZIP ~1.07 MB verified incl. migration 008,
PCP clean except intentional F3-35, audits-register row, GH release Latest). Prike saab
öelda: andmevoog oli kogu aeg õige — 3.5.0 näitab seda ausalt. Prior same day:
**F3-54 — the REAL Prike fatal found and fixed; v3.4.3 RELEASED
(critical).** Martin's correction (fatal at the option guard, line 166; admin off-toggle
didn't stop it) invalidated the F3-53 poison-row theory: the crash is OUR seam —
`SettingsEndpoint::save_woocommerce` wrote `smaily_connect_abandoned_cart_status` as a
BARE BOOLEAN (WP stores `'1'`/`''`) while THREE consumers offset into it as an array
(legacy email pass; `Options::get_woocommerce_settings_from_db`; inverted in
`EnvDetector`, where `(bool)` on a disabled array read as ENABLED). PHP 8 repro'd:
`'1'['enabled']` and `''['enabled']` both throw "Cannot access offset of type string on
string". Every store that saves the WooCommerce tab / wizard Step 3 corrupts the option —
Prike crashed loudly; the dev env never saved that tab (option absent), and the guard
test seeded the option ITSELF in the array shape, so the seam was structurally invisible
(LESSONS §2.19). Fix (`8c1c9d2`): (1) `Options::abandoned_cart_status()` +
pure `normalize_abandoned_cart_status()` — ONE shape gate, all consumers read through it,
corrupted stores heal automatically; (2) the legacy email pass dispatches ROUTER-FIRST
(`AutomationRouter::trigger_automation('abandoned_cart', …)` — wizard mapping row is the
workflow source; multilingual + F3-48 force_opt_in + F3-44 exchange capture; ApiException
= transient → retry) with fallback to the legacy `autoresponder_id` for pre-wizard
stores; enabled-with-neither-source logs once per pass, carts stay pending; (3)
`save_woocommerce` writes the ARRAY shape and PRESERVES `autoresponder_id` (3.4.x
destroyed it); (4) hydrate reads via the normalizer. Tests: +6 unit (normalizer), +5
integration (`AbandonedCartSettingsSeamTest` — REAL writer + REAL reader in one
scenario). Gates: ci:strict exit=0 (unit 480, vitest 232 — one pre-existing FLAKY:
`EngineAutomationsSection` "filters INACTIVE workflows" failed once under the parallel
full run, passes isolated ×2 + on rerun; T2.4 surface, untouched here); integration FULL
137/652; sandbox connection snapshot/restored (tenant verified). **v3.4.3 RELEASED** per
recipe (clean build-hash `597ae8f`, ZIP verified ~1.06 MB, PCP-on-ZIP clean except the
intentional F3-35 finding, audits-register row added, GH release Latest). **Prike:
install v3.4.3 as a proper update — no manual option cleanup needed** (the normalizer
heals the corrupt value; the interim `wp option delete
smaily_connect_abandoned_cart_status` remains valid until then; the v3.4.2 legacy-cron
cleanup is included). Prior same day:
**F3-53 — Prike abandoned-cart PHP 8 fatal loop + legacy WP-Cron
resurrection fixed.** Prike (installed the new module over the old one, no in-place upgrade)
hit a 15-minute fatal loop on `smly_plus_abandoned_cart`: old-writer `cart_content` rows
deserialize to string items, `prepare_products_data()` read `$cart_item['product_id']`
unguarded (PHP 8 fatal, cart stayed `mail_sent NULL`, whole pass aborted every tick) — and
the legacy WP-Cron events were alive because `Lifecycle::activate()`/`check_for_dependency`
re-scheduled them after WPCronAuditor's one-time clear, with `Cron::smaily_sync_subscribers`
still add_action-registered (= the F3-47 language clobber runnable daily, on Prike of all
stores). Fixes: (1) poison-row hardening in the legacy email pass — non-array cart_content
terminal-marked + logged, non-array/keyless items skipped, per-cart `try/catch (Throwable)`
backstop terminal-marks a throwing cart (deterministic ⇒ would recur forever); (2)
`Lifecycle::set_scheduled_actions()` + both call sites REMOVED (AS owns scheduling;
deactivate clears stay); (3) the `smaily_connect_cron_sync_subscribers` add_action removed —
the mass-send is uninvocable (method kept for the upstream diff). Tests: +2
AbandonedCartGuardTest (poison shapes incl. the exact Prike string-items case + the
Throwable backstop via a throwing `pre_http_request`), +3 new LegacyCronScheduleTest
(no callback registered; WC activation doesn't re-arm; scheduler method gone);
EnvScrub now also clears `smaily_connect_abandoned_cart_fields`. Gates: ci:strict exit=0
(PHPUnit unit 474, PHPCS 0 errors, PHPStan clean, vitest 232, tsc/eslint clean);
integration full suite OK (131 tests, 620 assertions; +5 new). NB the full suite
OVERWROTE the dev-site sandbox connection with fixture values (`re-fixture.test` /
"MiuMjau"-named fixture) — snapshotted before, restored after, verified
`tenant=Smaily Connect test, connected=1`; §2.17's "scrub touches only the tests site"
does NOT hold for the connection options, follow the CLAUDE.md snapshot/restore rule.
Docs: DECISIONS F3-53, LESSONS §2.18 (+ restored the `## 3` header §2.17's commit
accidentally ate). Client-side mitigation sent to Prike's dev meanwhile: abandoned cart
OFF in settings + `wp cron event delete` × 3 legacy events. Same day, Erkki's call
("teeme kohe korda ja siis release"): **F3-53 addendum — abandoned-cart `language` now
routes through ContactLanguageResolver** (`0014d76`; the legacy helper's cron fallback
sent `language:''` = wipes the contact's stored language — the F3-47 class at
abandoned-cart scale; key omitted when unresolved, integration test captures the real
wire body at the `pre_http_request` seam and pins `language='en'`). Then
**v3.4.2 RELEASED** per the CLAUDE.md recipe: bump in all six places + CHANGELOG.md
backfilled 3.2.0–3.4.1 (it had stalled at 3.1.0), committed BEFORE the build → clean
build-hash `d6fb061`; ci:strict exit=0 (re-run after bump); integration FULL suite OK
(132 tests, 627 assertions; connection snapshot/restored); admin+client+blocks rebuilt;
i18n skip per recipe (no admin-string/.po changes, artifacts current); prod-vendor ZIP
built + verified (v3.4.2 everywhere, required present, dev artifacts absent, ~1.06 MB);
PCP on the BUILT ZIP clean except the intentional `plugin_updater_detected` (F3-35);
audits-register 3.4.2 gate row added. GH release `v3.4.2` on the fork (normal release,
Latest), ZIP attached. **Prike next step: install v3.4.2 as a proper plugin update** —
the update itself clears the legacy WP-Cron residue (`maybe_run_upgrade → Activation::run
→ WPCronAuditor`); then abandoned cart can be re-enabled (poison rows will terminal-mark
+ log on the first tick). **Open (BACKLOG): rewrite abandoned-cart onto the new
namespaced pipeline** (own store shape instead of `serialize(get_cart())`, guest
capture, Event Log observability) — deferred, legacy path is hardened; separate sub-PR
with its own plan/checkpoint. Prior:
**v3.4.1 RELEASED — T2.4 pilot-feedback UI fixes + contract
v1.2.0 sync (`recipe_en`).** Patch release per the CLAUDE.md recipe: version bumped in all
six places, committed BEFORE the build → clean build-hash `ae3bc3d`; ci:strict exit=0
(PHPUnit unit 474, PHPCS 0 errors, PHPStan clean, vitest 232, tsc/eslint clean);
admin+client+blocks rebuilt; i18n artifacts current from the same-day T2.4 build-i18n run
(no admin-string changes since — skip per CLAUDE.md); prod-vendor ZIP built + verified
(v3.4.1 everywhere, required present incl. `dist/admin/admin.js`,
`dist/public/js/sc-runtime.js`, `blocks/*/build`, `vendor/autoload.php`, `composer.json`,
`languages/*.mo` + admin-bundle JSON; tests/docs/node_modules/admin-src/dev-vendor absent;
~1.06 MB); **PCP against the BUILT ZIP clean except the single intentional
`plugin_updater_detected`** (F3-35). No security re-audit — delta is React-admin-only
(shipped-PHP delta = version-bump lines; re-audit policy not triggered; judgement recorded
as the 3.4.1 row in `docs/audits/INDEX.md` per the 3.3.x lesson). No integration full-suite
run (PHP delta mock/test-only; sandbox connection preserved; the T2.4 automations-suite run
was OK 6). GH release `v3.4.1` on the fork (normal release, Latest), ZIP attached. Prior
same day:
**T2.4 — engine-automations UI feedback fixes (Erkki's real-store
test of v3.4.0; F3-52 addendum).** Five fixes, all React admin — PHP untouched. (1) **Language
mode is STORE-GLOBAL** (F3-52 addendum): the display mode derives ALWAYS from the store's
structure (`deriveLanguageMode`), uniformly for every row; a server row's stored
`language_mode` is a wire fact only and is never honoured for display (the sandbox's
walk-saved `single` `replenish_due` row rendered one dropdown while its neighbours got the
per-language table). New pure `convertAutomationMap` translates stored maps at hydrate —
single `{id}` → per_language `{fallback:id}` (languages unpicked); per_language → single
`{id:fallback}` (no fallback → `{}`, merchant re-picks) — and the PUT sends the derived mode.
(2) **Cooldown input UX:** a local typing draft (empty allowed — no snap-to-0, no "0…"
prefix), commit clamped to 1–365 on blur, an empty/garbage draft reverts to the previous
value; implemented as a section-local `CooldownField`, the shared NumberInput primitive's
immediate-commit behaviour untouched (other call sites). (3) **Empty test-address warning:**
an `enabled && test_mode && test_emails=[]` row gets an inline warning-tone note (the engine's
test fire path sends ONLY to the listed addresses — an empty list means nobody ever gets an
email, silently); a warning, not an error — saving stays allowed. (4) **Human generic error
banner:** `classifyAutomationsFailure`'s generic branch now leads with a human message
("Connecting to Smaily Campaign Intelligence failed (HTTP nnn). Check the connection on the
Campaign Intelligence tab and try again.") and demotes the raw technical error (the old
"GET /… → 401" headline, Erkki's deleted-key case) to a new `AutomationsFailure.detail`
rendered as a small detail line; the save path appends the detail in parentheses; the
key_rejected banner already pointed to the Campaign Intelligence tab — unchanged.
(5) **Forward-compatible `recipe_en?`:** the catalog type gained the optional field; non-et
admin locales show it when present, else `recipe_et` (`pickRecipe` — consistent with the
name/description locale logic). Mock + contract deliberately NOT touched — they sync in
their own pass after the engine deploy lands (do not depend on it). i18n: 3 new strings,
et translations complete (build-i18n run twice — extract then compile; 0 untranslated;
translations verified in the admin-bundle JSON). Tests: +24 vitest (conversion both
directions + uniform-mode buildRows, pickRecipe, save-path detail, classifier messages/detail
×6, cooldown draft/revert/clamp ×3, empty-address warning, recipe_en render ×2, banner detail
line; the Settings save-helper now blur-commits like the real flow). Gates: ci:strict exit=0
(PHPUnit unit 474, PHPCS 0 errors, PHPStan clean, vitest 232, eslint/tsc clean); PHP delta
zero — no integration run (would scrub the sandbox connection). Prior same day:
**v3.4.0 RELEASED — engine-run automations settings (T2) GA.**
Release gate on top of the T2.3 live-walk + T2 security re-audit (both below, same day):
version bumped in all six places (plugin header/constants, package.json, readme.txt stable
tag + changelog + upgrade notice, ConstantsTest, both test bootstraps), committed BEFORE the
build → clean build-hash `aa42ce6`; ci:strict exit=0 (PHPUnit unit 474, PHPCS 0 errors,
PHPStan clean, vitest 208, tsc/eslint clean); admin+client+blocks rebuilt; i18n artifacts
current from the T2.2 build-i18n run (no admin-string changes since — skip per CLAUDE.md);
prod-vendor ZIP built + verified (v3.4.0 everywhere, required files present incl.
`dist/admin/admin.js`, `dist/public/js/sc-runtime.js`, `blocks/*/build`, `vendor/autoload.php`,
`composer.json`, `languages/*.mo` + admin-bundle JSON; tests/docs/node_modules/admin-src/
dev-vendor absent; ~1.05 MB); **PCP against the BUILT ZIP clean except the single intentional
`plugin_updater_detected`** (Update URI clobber-guard, F3-35 — note the old `(BETA)` name
finding is gone since GA). GH release `v3.4.0` on the fork (normal release, no prerelease),
ZIP attached. No integration full-suite run in this pass (would scrub the sandbox connection;
PHP delta since the T2.1-gated suite is zero). Prior same day:
**T2.3 — automations live-walk GREEN against the real engine
(sandbox).** Dev wp-env re-connected via secret-safe STDIN exchange — the stale connection
still pointed at PRODUCTION MiuMjau (the exact CLAUDE.md trap); now `tenant_name="Smaily
Connect test"`, verified before any traffic. New `bin/walk-t2-automations.cjs` (tenant
hard-gate `sandbox_tenant_not_production`, all calls through the plugin's
`Client::automations_*` methods, not curl): **15/15 checks PASS** — §11 catalog 200 with the
4 pet-sector triggers (`replenish_due, winback_rescue, life_stage, post_purchase`), all 6
fields + `language_modes=["single","per_language"]` + `docs` URL; §12 initial config 200
(0 rows, fresh tenant = fail-closed); §13 valid PUT (all 8 fields, test_mode=true) →
`{ok:true, upserted:1}`; GET round-trip returns every value unchanged **and
`configured_via='plugin'` (the brief's acceptance criterion)**; invalid PUT A (enabled
without `automation_map.id`) → 422 with the v1.1.0 INDEXED `errors[]`
(`{index:0, trigger_key, field:"automation_map", message:"automation_map.id on nõutav"}` —
live proof the indexed shape is deployed); invalid PUT B (`per_language` without
`fallback`) → 422; invalid key → 401 `unauthorised`; fail-closed cleanup PUT
(`enabled=false, test_mode=true`) verified by GET — no enabled placeholder row left in the
sandbox. **QA checklist (brief) coverage:** (1) catalog renders from the API/new trigger
without a release — walk `catalog_get_200` + vitest "renders an unknown catalog trigger
dynamically" (browser render = manual pilot check); (2) no cross-store catalog cache — code
fact: the proxy/UI cache NOTHING (F3-51 rule 1), every GET hits the engine; (3) enabled
without workflow-id → 422 at the field — walk `put_invalid_missing_id_422_indexed` + vitest
`issuesByTrigger` binding + pre-validation tests (in-browser field render = manual pilot
check); (4) per_language without fallback → 422 — walk `put_invalid_no_fallback_422` +
vitest; (5) reopen shows GET state — walk round-trip + F3-52 rule 4 (fetch on every open,
no cache) + vitest dirty-draft tests (browser reopen = manual pilot check); (6) test_mode
default true + separate confirmed go-live — vitest fail-closed default row + "requires a
confirm before switching test mode off"; walk proves the test_mode=true round-trip
(confirm dialog in browser = manual pilot check); (7) missing/bad key → clear error — walk
`invalid_key_401` + unit 401→502 `api_key_rejected` mapping + vitest key-rejected banner.
The brief's final acceptance leg — test address receives the email on the nightly engine
run — is engine-side, NOT plugin-provable: manual pilot check. **Security re-audit on the
T2 surface run same day** (`docs/audits/2026-07-07-SECURITY_RE_AUDIT_T2_AUTOMATIONS.md` +
INDEX row): 0 Critical/High/Medium; 1 Low FIXED in the pass (engine-origin `docs` URL now
scheme-guarded `isHttpUrl()` before rendering as an anchor href); 3 Info accepted; also
swept the un-registered F3-49/F3-50 delta — clean. Prior same day:
**OrderBackfill full-suite flake RESOLVED — stale live-walk
order residue, NOT cross-test state.** The 3 recurring `RecEngineOrderBackfillTest` count
failures (+1 on every order count) were caused by ONE order sitting in the dev wp-env
`wc_orders` table since 2026-06-19: the F3-43 live-walk's `wc-label-printed` custom-status
order. Its cleanup used `wp_delete_post()` — a silent NO-OP for HPOS orders — and the
test's own `delete_all_orders()` swept via `wc_get_orders` + registered statuses, which
cannot see an unregistered custom status, while the backfill's F3-42 denylist SQL counts
it as a sale. Once probed (raw wc_orders dump at assert time) the failure was
deterministic, isolation included — the earlier "green in isolation" reads reflected
different env state, not test ordering. Fix: `delete_all_orders()` now sweeps STATUS-BLIND
off the active order table (reuses `OrderBackfillJob::table_spec()`); order cleanup in
`RecEngineOrdersTest::tearDown()` + `bin/walk-f3-43-orders.cjs` + `bin/walk-3.3-orders.cjs`
switched to `wc_get_order()->delete(true)`; bonus one-liner — `RecEngineMockServer::
terminate()` uses `defined('SIGTERM') ? SIGTERM : 15` (pcntl absent in the wp-env CLI →
end-of-run fatal gone). Asserts untouched. Gates: full integration suite green TWICE in a
row (`OK (126 tests, 594 assertions)` both runs, no shutdown fatal), ci:strict exit=0.
LESSONS §2.16. Prior same day: **T2.2 — engine-triggered automations config, React UI (F3-52)**.
The "Engine-run recommendation automations" sub-section renders UNDER the store-run
WooCommerce automations (Step 3 / WooCommerce tab): catalog-driven trigger cards (§11 —
no hardcoded keys, a new engine trigger appears without a plugin release; `_et`/`_en` copy
by admin locale, `recipe_et` + catalog `docs` link always), per trigger enable-toggle +
workflow picker (useWorkflows; per-language rows + fallback radio on multilingual A/B sites
→ `language_mode:"per_language"`, else `single`), cooldown 1–365 (default 7), and the
fail-closed test-mode block (`test_mode` default ON, up-to-50 test addresses, "Activate for
real…" as a SEPARATE confirmed action — never the enable toggle). ONE Save, TWO parallel
requests: the engine slice joins the WooCommerce tab's sticky-footer Save (and the wizard
Step-3 Continue) but keeps its OWN dirty bit — on partial failure (local POST ok, engine PUT
failed) only the engine section stays dirty with its error in-section; §13 all-or-nothing ⇒
a 422 keeps the whole slice dirty, errors[] bound to rows/fields by trigger_key/index.
Round-trip: GET on every open is the truth (dirty draft survives a tab switch), PUT sends
every rendered row with all 8 fields, `daily_cap` passes through GET→PUT untouched, §12
read-only fields stripped at hydrate; a config row missing from the catalog is neither
rendered nor sent. Not connected → upsell banner (Settings: CTA to the Campaign Intelligence
tab; wizard: next-step hint + a post-connect "back to Step 3" banner in Step 4). Errors: 503
→ upsell state; 502 api_key_rejected → reconnect banner; other → retry banner; skeleton on
load. New: api/automations.ts, state/engine-automations.ts (pure rows/validation/save-orch),
hooks/useAutomationsData.ts, components/steps/EngineAutomationsSection.tsx; slice + 5 actions
in the shared reducer. i18n: bin/build-i18n.sh run, all new strings translated in
smaily-connect-et.po (0 untranslated). Tests: 44 new vitest (engine-automations logic 28,
reducer slice 4, section component 9, Settings save-orchestration/partial-failure 3).
DECISIONS F3-52. Gates: ci:strict exit=0 (PHPUnit unit 474, PHPCS 0 errors, PHPStan clean,
vitest 208, tsc/eslint clean); PHP untouched — no integration run required for this UI
sub-PR. **Next:** T2 live-walk against the sandbox when the engine side is ready (the
OrderBackfill full-suite flake is RESOLVED — see the top entry). Prior same day: **T2.1 —
the PHP layer** below.)_

_(T2.1 record follows — same day:_ **T2.1 — engine-triggered automations config, PHP layer (F3-51)**.
Contract synced to **v1.1.0** (commit 9ec2ff8, engine c16377e+7b5b922, byte-identical md5
`7e41726bcd17fab163586b7f97093e0d`): §11 GET /automations/catalog, §12 GET /automations/config,
§13 PUT /automations/config — the engine-run automations (replenishment/win-back enrolment into
merchant-built Smaily workflows) get their plugin-side CONFIGURATION surface; execution never
touches the plugin. T2.1 ships the PHP layer in the same pass as the sync (LESSONS §2.7 — mock
+ code move with the doc): `Client::automations_catalog()/automations_config()/
put_automations_config()` (map keys `automations_catalog`/`automations_config` + new
`PATH_AUTOMATIONS_*` fallbacks — load-bearing for every pre-v1.1.0 connection, "Map age" §1;
first PUT verb in the Client, wire-pinned), mock-engine routes with strict §11–§13 validation
(all-or-nothing indexed 422, Estonian custom-check messages, engine-stamped
`configured_via`/`updated_at`) + `automations_*` endpoints-map keys, and the admin REST proxy
`REST\AutomationsEndpoint` (GET catalog / GET config / PUT config; `is_connected()` gate 503;
**no cache, no wp_options copy — the engine's GET is the source of truth**; PUT forwards
`configs` as-is, engine 422 passes through verbatim, engine 401 → 502 `api_key_rejected`; wired
via EndpointRegistry + expected_routes). Tests: ClientAutomationsTest (8) +
AutomationsEndpointTest (9) unit; RecEngineAutomationsTest integration (catalog shape, PUT→GET
round-trip, all-or-nothing 422 with row/field binding, wrapper-422 without index, 401, gate).
DECISIONS F3-51. Gates: ci:strict exit=0 (PHPUnit unit 474, PHPCS 0 errors, PHPStan clean,
vitest 164, tsc/eslint clean); integration — RecEngineAutomationsTest `OK (6 tests, 83
assertions)` in isolation; full suite 126 tests / 590 assertions with **3 pre-existing
RecEngineOrderBackfillTest failures that reproduce IDENTICALLY on clean main** (stash-verified,
same env/day: 120 tests, the same 3 failures without any T2.1 code — NOT a T2.1 regression;
**RESOLVED 2026-07-07, see the top entry**: the cause was a stale custom-status order the
2026-06-19 F3-43 live-walk left in the dev DB, not cross-test state). Next: T2.2 — the React
settings UI (**shipped, see the T2.2 entry above**).
Prior: 2026-07-03 —)_

**v3.3.2 — browse 0-events on CookieYes RESOLVED the RIGHT way (F3-50)**: root cause was the missing free `wp-consent-api` companion plugin, NOT a CookieYes incompatibility (CookieYes registers into the WP Consent API once it's installed). The 3.3.1 CookieYes cookie-parser was a mis-fix (Erkki caught it — per-vendor code + CookieYes docs prove standard support) and is **reverted**; 3.3.2 keeps browse consent on the standard WP Consent API + adds a `NotificationManager` admin advisory guiding merchants to install `wp-consent-api`. MiuMjau fix = install that plugin (wp-admin). Prior same day: **v3.3.1** (reverted) and **F3-49 DONE — browse events carry `smaily_visitor_token` (cold-start), NOT rec_id/email; browse attribution stays order-signal-driven** — resolves the browse-identity gap Erkki raised 2026-07-01. The beacon sent only `session_id`, so contract §6 per-event identity-resolution + retroactive-binding never fired and the async order-attribution path-3 was inert. Engine-team answer (2026-07-03): browse does NOT feed attribution (order `smaily_rec_id` + email-click drive `direct`/`exact_later`/`indirect_*`; browse would at best give the soft `assisted_view`) — so we DON'T add rec_id/email to browse, but DO add the opaque `smaily_visitor_token` for future cold-start personalization (the engine binds the browse row via it; ingest already accepts the field). Profiling opt-out on the token path is engine-side (server-enforced 2026-07-03); guest-browse-session-only = accepted v1 limitation. Wired into `enrich()` (omit-on-empty) + JS/integration/live-walk coverage; DECISIONS F3-49. Prior: 2026-06-30 — **F3-47 SP-A DONE — contact-sync language via `ContactLanguageResolver`**.
Managed (non-pilot) client Prike: ~1000 Smaily contacts drifted to language `en`. Root cause —
the upstream plugin's daily "sync all subscribers" cron derives language from the cron-unsafe
`Helper::get_current_language_code()`, which in a cron tick returns `get_locale()` = the WP
**site** locale; Prike's WP locale is `en` but its WPML content default is `et`, so the cron
mass-pushed `en` daily and out-raced the merchant's (correct) Make automations
(`_user_preferred_language`/`wpml_language`, default `et`). Our own new live path had a sibling
latent bug (`get_user_locale()` + always-set `language` key). Fix (SP-A): one shared
`Support\ContactLanguageResolver` — context-independent, mirrors the Make sources
(`_user_preferred_language` → latest order `wpml_language` → WPML default via `DetectorFactory`
→ site-locale short code), normalises to `et`/`en`, and **omits `language` on empty** (absent
preserves Smaily's value, empty wipes). Wired into `HookHandler` (`for_user`/`for_order`,
omit-when-empty on all three payloads). Robust **without a live-store data check** (Erkki has no
direct shop access — we ship only the plugin): the latest-order tier preserves the non-`et`
minority. Decisions (Erkki): sync **all** registered customers regardless of consent; **no
guests**; **never send `is_unsubscribed`** (Smaily owns consent — the legacy path reset it,
another reason to migrate Prike off it). Contact sync is `setup_completed`-gated, independent of
the rec-engine → ships before Prike goes on the engine. Gates: ci:strict exit=0 (PHPUnit 404
+13, JS 158, PHPStan clean, PHPCS 0 errors); integration OK 119. **SP-B DONE** — `BackfillJob::build_subscriber_payload` adds `language` via the same resolver
(omit-on-empty), so a one-off backfill run = the corrective mass re-sync of the ~1000 (re-sends
each contact with the resolver's language, not the cron's stale `en`). **Pending sub-PRs:** SP-D
(replace legacy daily-cron bridge `on_contact_sync_tick` so the `en`-clobber stops — until then
the backfill fixes contacts but the daily cron can re-drift them), SP-E (lock `is_unsubscribed`
out of the payload), SP-G (cutover: Connect plugin → wizard → Make data-sync off). **NOTE: the
ad-hoc SP-D/SP-E plan is now SUPERSEDED by the F3-48 contact-sync mode engine** (below) — the
cron takeover + `is_unsubscribed`/`force_opt_in` handling fold into that engine.

**RELEASED v3.3.1 (2026-07-03)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `8af6bc1`, ZIP ~1000 KB attached, tag `v3.3.1`, Latest, NOT a pre-release). Headline:
**CookieYes consent bridge** — browse tracking sent 0 events on CookieYes stores because the
beacon is fail-closed on the WP Consent API (`window.wp_has_consent`), which CookieYes doesn't
expose (confirmed live on MiuMjau). `detectConsent()` now falls back to CookieYes's own
`cookieyes-consent` cookie (grant on `action:yes` + `advertisement`, filterable), WP Consent API
keeps precedence, `cookieyes_consent_update` re-triggers mid-session. Once browse fires it carries
the 3.3.0 `smaily_visitor_token`. Gates: ci:strict exit=0 (PHPUnit 456, vitest 170 incl. 6 new
CookieYes cases, static clean); integration OK (120 tests, 499 assertions). **No PCP re-run /
security re-audit** — PHP surface delta is two `apply_filters` lines in the boot blob (no
REST/auth/SQL/crypto); substantive change is JS. **MiuMjau gets the fix via the normal plugin
update** (wp-admin — Erkki has no server file access, so the mu-plugin unblock was ruled out).
DECISIONS F3-49 / LESSONS §2.15.

**RELEASED v3.3.0 (2026-07-03)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `16a530f`, ZIP ~996 KB attached, tag `v3.3.0`, marked Latest, NOT a pre-release). Headline:
**F3-49** — browse events now carry the opaque `smaily_visitor_token` (identity for the engine's
future cold-start personalization binding, NOT attribution), while `smaily_rec_id`/email stay OFF
browse (data-minimization, client-side). Resolves the browse-identity gap Erkki raised 2026-07-01;
the engine team confirmed (2026-07-03) browse does NOT feed attribution (order signals drive the
`direct`/`exact_later`/`indirect_*` mix) and asked only for the visitor token. Gates: ci:strict
exit=0 (PHPUnit 456, vitest 164, PHPStan/PHPCS/tsc/eslint clean); integration OK (120 tests, 499
assertions); **browse live-walk 14/14 green** vs the SANDBOX ("Smaily Connect test", NOT MiuMjau),
incl. `engine_accepts_browse_visitor_token`. **No PCP re-run / security re-audit** — the shipped PHP
surface is unchanged from 3.2.1 (JS + tests only, version strings aside; no REST/auth/SQL/crypto
surface touched); 3.2.1 PCP was clean except the intentional `Update URI`. DECISIONS F3-49. **MiuMjau
+ Prike get this via their next deploy** (browse bundle `sc-runtime.js` rebuilt in the ZIP).

**RELEASED v3.2.1 (2026-07-01)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `4b6fd3f`, ZIP ~992 KB attached). Headline: the **F3-48.5a contact-sync mode-selector UI
refinement** (mode card visible only when sync enabled + below the sync toggle; "Checkout opt-in only"
disabled until the checkout checkbox is on; homepage radio-card style) + doc-accuracy fixes (the
"ships dormant" wording). Admin-UI-only, no functional/data change. Gated by the **F3-48 Smaily
contact-API live-walk 12/12 green** (`bin/walk-f3-48-contact-sync.cjs`, sandbox); PCP on the ZIP clean
except the intentional `Update URI`; ci:strict exit=0 (PHPUnit 456, vitest 161); ET i18n complete.
No security/code-quality re-audit (TSX-only delta, no security surface). **Next gate: Prike cutover.**

**RELEASED v3.2.0 (2026-06-30)** — full GH release on the fork (`erkkimarkus/smaily-wordpress-plugin`,
build `5034cc9`, ZIP ~991 KB attached). Headline: the **block-checkout rec-attribution fix** (the
MiuMjau `smaily_rec_id`-empty regression — MiuMjau runs block checkout, so the cookie was captured
but never stamped onto the order; now stamped via `woocommerce_store_api_checkout_order_processed`)
+ the **F3-48 contact-sync mode engine** (the *mode selector* — consent presets — is configured in
the wizard/Settings; on an already-set-up install (`smly_plus_setup_completed=true`, e.g. MiuMjau)
the cron-safe contact-language + consent sync go live immediately on upgrade, same credentials, no
re-wizard. The legacy daily mass-sync behind the `en`-drift is cleared by `WPCronAuditor` on
upgrade **regardless** of wizard state — only the *live per-event* sync ownership and the mode
selector are gated by `setup_completed`). Both re-audits'
findings fixed; PCP on the ZIP clean except the intentional `Update URI`. **MiuMjau needs this build
deployed** to fix attribution; then the manual live block-checkout acceptance test.

**F3-48 Smaily contact-API live-walk — DONE & GREEN (2026-07-01, `bin/walk-f3-48-contact-sync.cjs`,
12/12 against the `smailydemo` SANDBOX).** Drives the real `Smaily\Connect\Smaily\Client` over live
Smaily: contact upsert code 101 with a SHORT `language` (`et`) + custom fields; `is_unsubscribed`
0→1→0 round-trip (F3-48.6) read back via `contact.php?email=`; absent-language upsert accepted
(omit=keep); `history.php` (reconcile delta) + `list=1` (rebaseline) + autoresponder-list shapes;
`autoresponder.php` accepts the `force_opt_in` param. Two LIVE divergences the mock hid, both now
handled in the walk (NOT plugin bugs — the form-encoded batch the Client sends returns 101 with a
valid domain): (1) live Smaily rejects RFC-6761 **reserved-TLD** emails (`.test`/`.example`/
`.invalid`) with code **203** "invalid data" — the walk uses `@example.com`; (2) `contact.php` is
**async** — an immediate readback after a 101 upsert misses (`206`), so the walk **polls**. Both
documented: LESSONS §2.14 + `re/docs/smaily-api/guides/gotchas.md` ("Reserved-TLD emails"). **Next
gate: Prike cutover** (Erkki installs Connect → wizard → preset 1 → Make off).
Pending follow-up: a Shopify-Connect feature-parity doc for the platform-agnostic changes (Erkki).

**F3-48 contact-sync mode engine — DESIGN APPROVED (Erkki, 2026-06-30); F3-48.1–.6 DONE (engine feature-complete).**
F3-48.5a (post-v3.2.0 UI refinement, Erkki 2026-06-30): in Step2Subscribers the mode-selector Card
is now gated on `state.subscriberSyncEnabled` and rendered **below** the "Contact synchronisation"
sync toggle (hidden when sync is off — the who-gets-synced question is moot then). The
"Checkout opt-in only" preset radio is `disabled` until the checkout subscription checkbox toggle is
on (`!state.checkoutSubscriptionCheckbox` → disabled + a hint line). Radio cards now use the shared
`Radio` primitive + the homepage card style (`border-brand bg-brand-soft-bg` when selected), matching
MultilingualModePicker instead of the hand-rolled `<input type=radio>`. TSX-only; ci:strict exit=0
(PHPUnit 456, vitest 161, tsc/eslint clean). Ships in the next release (after the F3-48 live-walk).
F3-48.6: consent opt-in/opt-out propagation (WP→Smaily) — a `user_newsletter` meta-transition
handler (consent mode) enqueues a separate `:consent` row (opt-in → is_unsubscribed=0, opt-out →
=1); routine data sync never sends is_unsubscribed. Fixed a latent bug found here: the Flusher
dropped the live contact-sync `language` (only the backfill sent it) — now forwarded. Regression
locks added. Gates: ci:strict exit=0 (PHPUnit 448, vitest 161), integration OK 119. **Remaining:
.7 — Prike cutover (Erkki installs the plugin, sets preset 1, Make off) + thorough end testing
(full gates + live-walk sandbox + security/code-quality re-audit + PCP against built ZIP + i18n
.pot/.po regen).**
F3-48.5: Settings/wizard UI — "Contact sync mode" Card in Step2Subscribers (3 radio presets +
legitimate-interest warning Banner + include_guests checkbox + preset-1-only force-opt-in toggle),
wired through the wizard reducer → buildTabPayload/hydrate/settings-reducer →
SettingsEndpoint::save_subscribers (validated) + EnvDetector::saved_settings (boot). New English
`__()` strings — .pot/-et.po regen + ET translation is a packaging step (bin/build-i18n.sh). Gates:
ci:strict exit=0 (PHPUnit 442, vitest 161, tsc/eslint clean), integration OK 119. Remaining: .6
is_unsubscribed opt-out + regression locks → .7 Prike cutover + thorough end testing.
F3-48.4: `AutomationRouter::trigger_automation` passes `ContactSyncMode::automation_force_opt_in()`
(consent/checkout → never re-subscribe on trigger; legitimate interest → only with the advanced
toggle) instead of the hard-coded `true`. Gates: ci:strict exit=0 (PHPUnit 442), integration OK 119.
Remaining: .5 UI (preset selector) → .6 is_unsubscribed opt-out + regression locks → .7 Prike
cutover (+ thorough end testing per Erkki: full gates + live-walk + security/code-quality re-audit
+ PCP against the built ZIP).
F3-48.3 (cron takeover): `on_contact_sync_tick` no longer fires the legacy buggy mass-send (the
F3-47 site-locale clobber — now orphaned); it runs `ContactReconciler::reconcile()` (consent) +
a mode-aware refresh via non-clearing `BackfillJob::start(false)`, guarded by
`should_start_refresh()` (skip while running / re-arm once per freshness window). Gates:
ci:strict exit=0 (PHPUnit 441), integration OK 119. Remaining: automation force_opt_in → UI →
is_unsubscribed+locks → Prike cutover.
F3-48.2: `ContactReconciler` (Smaily→WP marketing-consent mirror, consent mode) + `Client::
get_action_log()`/`list_contacts()`. Delta-first — standing reconcile polls the Smaily action-log
(`history.php` + `since_seq_id`) for optin/optout/delete/complaint deltas (O(changes), light on
shared hosting); full `list=1` pull only as an occasional re-baseline. Marketing-only (never
profiling). New cross-team doc `docs/CONSENT_STRATEGY_COMPARISON.md` (engine vs plugin consent
layers). Not yet wired — cron takeover next. Gates: ci:strict exit=0 (PHPUnit 436), integration
OK 119.
F3-48.1: `ContactSyncMode` (preset→policy) + `ContactAudience` (mode-aware audience) wired into
the HookHandler live `contact.sync` gate + BackfillJob audience filter; default `consent`
narrows the new path to `user_newsletter=1` (matches legacy), legit-interest syncs all. Gates:
ci:strict exit=0 (PHPUnit 425, PHPStan clean, PHPCS 0 errors); integration OK 119. Remaining
sub-PRs below. Different stores need
different sync behaviour by lawful basis: Prike wants ALL customers (legitimate interest;
legacy only synced `user_newsletter=1` → the missing-contacts root cause); Client 2 wants
consent + Smaily↔WP reconcile; Client 3 wants checkout-opt-in-only/guests. Decision (Erkki):
named **presets** (not a toggle matrix), default = consent (lawful-safe AND matches legacy's
opt-in filter, so upgrades never silently broaden). Three presets: All customers (legit
interest) / Subscribers only (consent, default) / Checkout opt-in only. `include_guests`
checkbox default off; bidirectional reconcile; **automation `force_opt_in` is mode-driven**
(unifies the AutomationRouter-always-true vs legacy-abandoned-false inconsistency;
`force_opt_in` is an undocumented Smaily param being added to `../re/docs` by a separate
agent). UI = radio-card presets in Step2Subscribers + warning Banner for legit-interest.
Full design: `docs/CONTACT_SYNC_MODES.md`; DECISIONS F3-48. Both open questions resolved
(preset-1 `force_opt_in` defaults `false` + advanced preset-1-only toggle; preset labels kept).
Implementation sequence: mode core → reconciler + cron takeover → automation force_opt_in → UI
→ regression locks → Prike cutover. Builds on the shipped F3-47 language resolver
(mode-independent). Interim mitigation while
Prike is still on the old plugin: uncheck "Language" in its Subscriber Synchronization (stops the
daily `en` overwrite — omitted field preserves the existing value). DECISIONS F3-47). Prior — 2026-06-26 (**F3-46 DONE — server-side rec-attribution landing capture**.
Engine brief `PLUGIN_BRIEF_woo_rec_link_redirect.md` (rev 2): prod shows 374 orders/30d, 0
with `smaily_rec_id` — attribution empty. Root cause: the capture→stamp→send chain already
existed end-to-end (`HookHandler` reads cookie `smaily_rec_id` → order meta → `OrderPayloadBuilder`),
but the ONLY cookie producer was client-side JS (`StorefrontBeacon`/`captureUrlParams`), gated
behind browse-tracking + marketing consent + not-ad-blocked → it never fired on the pilot. Fix:
new `Integrations\WooCommerce\LandingCapture` on `template_redirect` writes the SAME cookies
server-side, ungated by the beacon path (DECISIONS F3-46). **Two decisions (Erkki):** (1)
**follow the contract, not the brief** — capture `smaily_rec`→`smaily_rec_id`/`smaily_vt`→`smaily_rec_uid`/`smaily_ctx`→`smaily_rec_ctx`
(the brief's `smre_*`/`utm_content`/90d diverge from the byte-synced §"Cookie names"); accept
`utm_content` only as a fallback guarded by `utm_source=smaily`+uuid; **feedback sent to the
engine team** to realign their brief; (2) **capture unconditionally when connected** — rec
attribution is a first-party functional signal (rec_id uuid + opaque visitor token, no PII),
decoupled from the browse beacon; browse/Layer-2 consent stays separate; `smaily_connect_capture_attribution`
filter is the escape-hatch. Zero downstream change (no HookHandler/builder edit). Out of scope:
the brief's optional redirect endpoint (§3.4, YAGNI), Layer-2 site-wide vid, the pre-existing
block-checkout stamping gap. Gates: ci:strict exit=0 (unit 391 +17, JS 158); integration OK 119
(+5). **The real click→land→buy→attribute round-trip is a manual pilot check** (server path is
unit+integration-proven; the browser moment is not walk-coverable). **Released `v3.1.0`** — full
GitHub release on the fork (`erkkimarkus/smaily-wordpress-plugin`, build `904f4ab`, ZIP ~994 KB
attached) — the pilot needs this to fix the empty attribution; after install, do the manual
rec-link round-trip check. Release gate: PCP against the BUILT ZIP clean except the intentional
`Update URI` (F3-35); a focused security pass on the new `$_GET`/cookie surface found no new
findings (audit register row added). Prior: **`v3.0.1` RELEASED** — full GitHub release on the fork
(`erkkimarkus/smaily-wordpress-plugin`, build `a34ed40`, ZIP 966 KB attached): the React
admin UI internationalization (W-7) + the W-5 enqueue refactor + a **complete Estonian
translation** (all 275 strings; admin UI + blocks + PHP). Translation-only, no functional
change. `bin/build-i18n.sh` rebuilds `languages/*.mo`/`*.json` (incl. the admin-bundle
catalog `…-et-464ceaab….json`) reproducibly. Gates: ci:strict exit=0 (unit 374, JS 158);
integration OK 114; Playwright-verified full-wizard Estonian render (0 console errors); PCP
against the ZIP clean bar the intentional `Update URI`. These are the fork-side
upstream-readiness items (see `docs/UPSTREAM_MERGE_PROPOSAL.md`); **W-3** (remove `Update
URI`) stays until the actual wp.org merge, and the full-ET review by a native speaker +
upstream #119/#120/#128/#132 + the Smaily go/no-go remain. Prior: **Pre-3.0 GA audit pass**
— three read-only audits on the
`906cf3d..HEAD` delta (~151 files / +10.4k lines since the 2026-06-11 Fable audit):
Security, Code-quality + wordpress.org-readiness (incl. `wp plugin check` PCP 2.0.0),
relocated with the existing audit docs into a new **`docs/audits/`** folder + register
(`docs/audits/INDEX.md`) + a **re-audit policy** (CLAUDE.md). **Result: no
release-blockers; codebase well-built.** Security 0 Critical / 0 High (2 Low: admin-gated
SSRF on engine base_url, Event Log PII-at-rest cleartext; deps clean). Code-quality
GA/upstream-ready. Punch-list for the 3.0 cut: ABSPATH guard on ~29 shipped legacy files
(`includes/smaily-*`, `integrations/**`, `blocks/**`, …), drop "(BETA)" from the plugin
`Name` header, gate ~21 unconditional `error_log()` behind WP_DEBUG, `esc_html`/phpcs
the ~9 `ExceptionNotEscaped` throws, PCP polish, re-run PCP against the built ZIP. At
upstream merge: **remove the `Update URI` header** (F3-35 fork-only guard, not allowed on
wp.org). **Punch-list NOW APPLIED (full PCP-clean, Erkki's call):** ABSPATH guard on the
~29 legacy files, `error_log` → new `Support\DebugLog` (WP_DEBUG-gated, ~23 sites), file-level
`phpcs:disable` on the custom-table DAOs + justified ignores (DB/nonce/hookname/textdomain/
ExceptionNotEscaped), readme Upgrade-Notice trimmed, blocks `apiVersion` 2→3 (⚠️ needs an
editor smoke-test), `.zipignore` drops stale `dist/partials`+`dist/template` / `BACKLOG.md` /
`blocks/.eslintrc.cjs` and ships `composer.json`. **`wp plugin check` against the BUILT ZIP is
clean except the 2 intentional** (`Update URI`=fork guard, `(BETA)`=3.0 cut). ci:strict exit=0
(unit 374, JS 158). Lesson: run PCP against the ZIP with `--slug` (the dev-tree run hid `dist/`
+ blocks; a wrong unzip-dir name caused 255 false TextDomainMismatch) — CLAUDE.md updated.
**Then the 3.0.0 GA cut STARTED:** all tests green first (ci:strict unit 374 / JS 158;
integration 114; **a Playwright browser smoke-test confirmed the apiVersion-2→3 blocks
render in the WP 7.0 iframe editor with 0 console errors**); version bumped **2.1.0-beta.10
→ 3.0.0** across the header / both constants / package.json / readme Stable-tag+changelog
+upgrade-notice / the 3 test pins, and **"(BETA)" dropped** from the plugin Name (clears
PCP `mismatched_plugin_name`). **`v3.0.0` GA RELEASED** — built the `--no-dev` ZIP (974 KB,
no dev cruft, no `-dirty`, from commit `910f632`); **final `wp plugin check` against the prod
ZIP = 1 finding, the intentional `Update URI`** (`mismatched_plugin_name` cleared by the BETA
drop); published as a **full (non-pre-) GitHub release** on the fork
(`erkkimarkus/smaily-wordpress-plugin`, tag `v3.0.0`, ZIP attached) — `release.yml` fires but
fails harmlessly (no wp-cli) and does not clobber the asset. Then the
**upstream-merge prep started** (the technical items the fork can do before Smaily
greenlights the takeover; see `docs/UPSTREAM_MERGE_PROPOSAL.md`): **W-5 DONE** — the
admin-notice dismiss moved from an inline `<script>` to an enqueued
`admin/js/notice-dismiss.js` (E2E-verified); **W-7 DONE** — the React admin UI is now
**fully internationalized** (~244 strings across 24 files wrapped with a `wp.i18n` shim
`admin/src/lib/i18n.ts`; `wizard.php` enqueues `wp-i18n` + `wp_set_script_translations`;
`bin/build-i18n.sh` reproducibly rebuilds `languages/*.mo`/`*.json` incl. the admin-bundle
catalog — make-pot can't read `.tsx` so it esbuild-transpiles first, and the catalog is
renamed to WP's expected `…-et-464ceaab….json`; a Playwright check confirms Estonian
renders end-to-end with 0 console errors; 21 representative ET strings translated, the
rest of the `.po` ready to fill). ci:strict exit=0 (unit 374, JS 158); integration OK 114.
**Still NEXT:** **W-3** remove `Update URI` (at the merge, not before — it's the fork
clobber-guard), reconcile upstream #119/#120/#128/#132, full ET translation of the `.po`,
and the Smaily go/no-go on the takeover. See `docs/audits/`. Prior:
**Faas 2: legacy admin settings-page removed (F3-45)** — the
redundant legacy `Admin` settings page + `Settings`/`Renderer`/`Sanitizer` + partials +
`smaily-admin.css/js` deleted; the subscription **widget** and Plugins-page **Settings
link** relocated (100% preserved); `Notices`/`Notice_Registry` KEPT (1.3.0 upgrade notice).
Hard constraint honoured: must not break the ~2000 installs — new UI owns the same option
keys, kept integrations unchanged. ci:strict exit=0; integration OK 114. Prior:
**Event Log stores the real request + engine response
(Problem 3 / F3-44)** — Details showed `Payload: []`; now order/catalog/Smaily rows store
the exact JSON sent + the engine reply (`sent_payload` / `last_response`, migration 007,
both queues), never the auth header; a terminal-skip records `outcome:"skipped"` (exposes
the silent "sent"). ci:strict exit=0 (unit 377, JS 158); integration OK 114. Prior:
**Order sync data-loss fixes (F3-42/F3-43)** — engine brief
order #58922: a guest order with a deleted product was marked "sent" but never POSTed.
F3-43: a deleted-product line is never dropped (keys `wc-oi-{item_id}`) so the order isn't
lost; F3-42: custom WC statuses (label-printed/shipped) default through as a sale
(denylist), on-hold now non-sale (reverses F3-22). ci:strict exit=0; integration OK 113;
live-walk 7/7 (`bin/walk-f3-43-orders.cjs`). The brief's Problem 2 (errors[]→FAILED) was
already built (F3-18); Problem 3 (store request/response in Event Log) is a deferred
follow-up. Prior: **F3-40 trash fix now live-walked 7/7 against the sandbox**
(`bin/walk-f3-40-trash.cjs`) — trash → `catalog.delete` with the clobber guard live
(`delete=1 upsert=0`), the engine ACCEPTS `in_stock=false`, untrash → `in_stock=true`
accepted, backfill trashed → delete accepted. Closes the mock-only gap F3-40 shipped
with, and confirms the 2026-06-18 pilot log errors were pre-existing orphan rows
cleared on retry, not a bug in the fix. ci:strict exit=0 (unit 359, JS 158). Also:
the Plugins-list **"Settings" link repointed from the legacy view to the new UI**
(`smaily-connect-settings`; Task 2 Faas 1) — the legacy view-layer teardown is a
separate pending sub-PR. Prior:
**Browse beacon renamed off "beacon" → `/relay` +
`sc-runtime.js` (F3-41)** — engine-team brief Teema 3: zero real browse events. After
the two-gate config was fixed (toggle on + CookieYes marketing consent), Erkki found
the storefront POST only succeeded with the **ad-blocker off** — the word "beacon" is
on EasyPrivacy filter lists and blocked both browser-visible names: the script
`dist/public/js/beacon.js` and the route `/wp-json/smaily-connect/v1/beacon`. Fix:
neutral names — script `dist/public/js/sc-runtime.js` (vite entry key only; source
`public/js/beacon.ts` unchanged), route `/relay` (`BeaconEndpoint::ROUTE`,
`StorefrontBeacon` beaconUrl, `EndpointRegistry`, browse live-walk, integration tests),
handle `smaily-connect-runtime`. **Consent unchanged** — first-party + WP-Consent-API
marketing-gated; only the filter-tripping name is neutral, not the consent. Internal
names (classes, `beacon.ts`, `window.smailyConnectBeacon`, `beaconUrl` key) keep
"beacon" on purpose (not browser-visible). Whether a blocker still catches `/relay` is
a **manual browser check** (200 with blocker on); the integration test only proves the
server dispatches `/relay`. Gates: ci:strict exit=0 (unit 359, JS 158); integration OK
113. DECISIONS F3-41; CLAUDE.md beacon-naming note. **Released `v2.1.0-beta.8-rc.1`**
(GH pre-release on the fork, build `e85bb2a`, ZIP attached; cumulative — includes the
F3-40 trash fix). Pilot: install → product re-import → confirm `POST …/v1/relay` = 200
with an ad-blocker on. Prior: **Trashed products kept in catalog as `in_stock=false`
(F3-40)** — engine-team 2026-06-17 brief, Teema 2: ~4% of pilot order lines had no
`catalog.sku` match (~567 rows / ~265 customers) → species un-inferable from
purchases. Erkki traced them to the WordPress **trash** (not permanent delete).
Root cause: trashing fires NO catalog hook (`before_delete_post` is
permanent-delete-only; trashing routes via `wp_update_post`), and the backfill was
`publish`-only → trashed-but-once-bought products go missing/stale and orphan the
`order_items.sku ↔ catalog.sku` join. Fix (A+B, the engine's "send `in_stock=false`,
don't drop" rule): `Bootstrap` binds `wp_trash_post → on_delete_product` +
`untrashed_post → on_save_product`; `CatalogBackfillJob` enumerates `publish`+`trash`,
sending trashed products as `in_stock=false` (kept for the join/training, not
recommended — the engine has no delete-by-key, so a `catalog.delete` row IS an
`in_stock=false` upsert). `is_removable` extracted to a shared static (F3-39 guard
reused on both paths). **Caught + guarded a real clobber bug** (live-hook integration
test, not review): `wp_trash_post()` also fires `save_post_product` → `on_save_product`,
which re-upserted `in_stock=true` and undid the removal — `on_save_product` now
early-returns on a `trash`-status save. Permanently-deleted products remain
unrecoverable (no WC data) — accepted. Gates: **ci:strict exit=0 (unit 359, JS 158);
integration OK 113**. DECISIONS F3-40; CLAUDE.md trash note. **Released
`v2.1.0-beta.7-rc.1`** (GH pre-release on the fork, build `bd8b9cb`, ZIP attached);
pilot still needs a **catalog re-backfill after install** to populate the trashed rows.
Brief's Teema 1 (order statuses: custom `label printed`/`shipped` etc. dropped by the
5-key `STATUS_MAP`) = client fixes WC-side, no plugin change; Teema 3 (browse beacon
0 events) = pilot config (toggle now on + CookieYes installed + marketing consent
given), watching for engine browse traffic. Prior: **`2.1.0-beta.6-rc.1` RELEASED** — GH pre-release on
the fork (build `b99eb15`, ZIP attached): engine **default URL →
`https://intelligence.smaily.com`** (migrated off the `*.vercel.app` preview
host). Static-reference-only change — the `Constants::SETUP_BASE_URL` default,
the connection-screen setup-URL placeholder, contract
base/setup/`engine_base_url`/curl examples, and the integration
connectivity-test base. **No contract/data/field/header (`X-Engine-Version`)
change**; the runtime path self-adapts (engine returns its live
`engine_base_url`, plugin derives the host from the pasted setup URL) and the
old `*.vercel.app` alias still resolves — existing installs need no action.
Prior: **`2.1.0-beta.5-rc.1` RELEASED** — display-name change only, the
rec-engine product renamed **Smaily Campaign Intelligence** across user-facing
surfaces (internal identifiers, REST routes, contract, endpoints unchanged; ET
translations updated). Prior: **`2.1.0-beta.4-rc.1` RELEASED** — GH pre-release
on the fork, bundles three fixes on top of beta.3: **CC.5 catalog.delete
auto-draft-burst fix** (skip never-published artifacts whose removal object the
engine 400s, F3-39 / LESSONS §2.12), Event Log actions column pinned right
(Retry/Details stay reachable), and backfill progress showing the honest
synced-product count instead of the misleading multilingual sent/raw-total
fraction. Awaiting Erkki's install to MiuMjau. — Prior: **catalog-correctness
CC.1–CC.4 DONE + RELEASED + DEPLOYED**;
multilingual fix, model **(B) {lang:value}** + structural signal
(NOT a filter, engine owns `recommendable`, F3-38). Released
**`v2.1.0-beta.3-rc.1`** (GH pre-release on the fork); Erkki DEPLOYED to MiuMjau;
**canonical re-sync running; engine team confirmed the data arrives in the correct
shape.** Go-live sequence: deploy ✓ → engine wipes MiuMjau SKU-graph → full
re-backfill (signal + customers.language ride along) → engine recompute (in the
wipe/rebackfill phase, continuing engine-side). CC.1 canonical adapter primitive;
CC.2 canonical SKU (catalog+orders+browse, via SkuResolver) + collapse + P4; CC.3
{lang:value} payload (500-char/lang); CC.4 product_type/is_virtual/is_downloadable
signal. Live-walk **9/9** (`bin/walk-cc3-multilingual.cjs`, sandbox). MiuMjau =
WPML + WCML (variations auto-resolve). OPEN: language-switcher `wc-49143` classify
(its product_type from the re-backfill / post-49143 inspection); MiuMjau gift-card
type string (self-heals on re-backfill); CI "Lint and test" PRE-EXISTING red
(integration-without-WC, not ours); dev wp-env sandbox conn scrubbed by the last
integration run (fresh token for future walks). Release/CI notes in CLAUDE.md. See
the catalog-correctness section below. Earlier 2026-06-12 late:
**engine go-live sync done** — results in
docs/ENGINE_TEAM_PILOT_SYNC_RESULTS.md; MiuMjau IS the pilot tenant (walks →
sandbox from now on, CLAUDE.md updated); engine fixed 2 catalog-ingest bugs
(the 91% retry-error rate was theirs); pilot needs: connection check after
the key-rotation window + Retry-all-failed again + browse enable + joint
GDPR run. Earlier: **P8 pilot day-1 fix: SkuResolver / F3-36** — the
pilot store has NO SKUs + old orders reference deleted products; catalog was
silently empty (pre-enqueue drop, no Event Log trace), orders D6-failed on
empty `items[]` (the 50 red rows), browse events rejected. Fix: synthetic
`wc-{id}` keys on all three surfaces + empty-items terminal skip + mock now
enforces items min-1; **2.1.0-beta.2**; LESSONS §2.11. Same day, P9: pilot
mass-email incident (sender = third-party CartBounty Pro, NOT us — but our
legacy pipeline had the same unbounded-backlog flaw, enabled) → F3-37
backlog guard + per-cart errors, rc.2. Live-walks ALL GREEN
(catalog 15/15 w/ new lock-proof lever, orders 12/12, browse 13/13); GH
pre-release **v2.1.0-beta.2-rc.1** published with the pilot ZIP. Next: pilot
redeploy + catalog re-backfill + Retry all failed. Also: health-notice
placement fix (wp-header-end). Earlier same day: **P7 upstream auto-update clobber guard** — upstream's
w.org 2.0.0 would have been offered/auto-applied OVER the fork; fixed with
`Update URI` header + renumber to **2.1.0-beta.1**; first GH pre-release
v2.1.0-beta.1-rc.1 with pilot ZIP. Same day, earlier: **P6 RSS feed URL builder** — pilot-prep finding:
old 1.6 RSS tab had no new-UI home (2.H.3 side-effect; the feed itself never
broke). Rebuilt stateless on Integrations step/tab; EnvDetector emits the
boot-payload `rss` block; DECISIONS F3-34. Previous day 2026-06-11: THREE update groups. **Upstream-merge prep
sub-PR (latest):** README feature-complete refresh; CHANGELOG.md created;
DECISIONS_DRAFT finalized as `docs/DECISIONS.md` (single-file log chosen over
ADR split); .pot regenerated + et.po updated with 39 new-string Estonian
translations (+ compiled .mo/.json now ship in the ZIP — upstream #120
reconciled: nothing to merge, fork already carried those); phase-4
cron-interval TODO decided (keep — public API); WP 7.0 is now the integration
BASELINE (suite 99/99, pre-pilot pin closed; pilot-repro recipe in CLAUDE.md);
fresh pilot ZIP cut. BACKLOG doc-drift row closed in full. **Audit + fixes:** full codebase audit (`docs/audits/FABLE_AUDIT.md`) → fix series F1–F6 all landed: F1 removed the 2.H.16 diagnostics that logged the Smaily password to debug.log (CRITICAL); F2 dead-ajax cleanup + audit corrections; F3 Cypher v2 AES-256-GCM + upgrade re-encryption (closes BACKLOG GCM, F3-32); F4 readme.txt rewritten for 2.0.0-beta.1 incl. the rec-engine external-services disclosure; F5 INSTALL.md profiling-opt-out section; F6 queue janitor + created_at index (BACKLOG item pulled forward, F3-33). Integration now OK 99. **Earlier:** `docs/TESTING.md` pilot-acceptance plan written from Erkki's business input — the "Erkki / business: TESTING.md" go-live item is closed; see the milestone section. Previous 2026-06-09: 3.9 Step-4 activation COMPLETE — locked design: connecting the rec-engine syncs ALL domains (system-decides), the four per-domain sync toggles (orders/customers/products/cart) were cosmetic/write-only and are REMOVED; browse-tracking is the only Step-4 toggle (legal-consent gate, opt-in default-off). Disconnect clears only the connection options and PRESERVES `smly_plus_rec_track_browsing`, so re-connect restores the toggle — which required a mandatory hydration fix (EnvDetector emits the saved value, hydrate reads it instead of hardcoding false; also fixes a plain-reload blanking bug). Dead option keys cleaned up idempotently on upgrade-detect. PLUGIN.md §Step-4-4a/§6 revised to match the vision; DECISIONS F3-29. Then a pre-3.9 task: PLUGIN.md translated ET→EN. Next: Phase 3 done; Smaily profiling-consent wiring + beacon two-gate stop is the remaining separate piece. POST-3.9: (i) **legacy-WC order-backfill verified** — WC 6.9.4 + PHP 8.1 env, real `wp_posts` traversal, full integration 75/75 on legacy (pilot precondition RESOLVED, see go-live checklist); (ii) a production-readiness audit surfaced two NEW pilot-blockers beyond features — failed-queue-row invisibility/no-re-drive (P1) and no surfaced diagnostic trail (P2) — plus a WC-version-header mismatch (header says 7.0, pilot is 6.9.4) and a missing pilot-onboarding doc; tracked for prioritisation. Then pilot-hardening began: **P5** version-floors reconciled (WC 6.9/WP 6.2/PHP 8.0); **3.10.0** Event Log visibility shipped — `/events` UNION read-model + Settings tab + sticky failed-banner + backfill progress now engine-confirmed sent/failed (no more "1400/1400 while failed"). Sequence ahead: 3.10.1 recovery → 3.10.2 notice → P4 onboarding doc; then Smaily-consent (awaits its spec). See pilot-hardening sequence below. **🎯 ALL of that now DONE — 2026-06-09: pilot-hardening complete (P5/3.10.0-2/P4) + (a) Smaily profiling-consent complete ((a).0 enforcement + (a).1 beacon two-gate + (a).2 My-Account opt-out UX + 9/10 live-walk, §10 accepted as 3.8-proven). PLUGIN-SIDE PILOT-FEATURE-COMPLETE; feature-complete ZIP cut. Remaining for go-live is non-plugin-code: TESTING.md (business), engine-frontend (engine team), manual/pilot verifications, the (a) TODOs. See the milestone section below.**)_

---

## The two-team picture

Two repos, one byte-identical contract (`docs/RECENGINE_API_CONTRACT.md`):

- **Plugin** (this repo) — WordPress plugin. Sends WooCommerce data (catalog,
  customers, orders, browse) to the recommendation engine via API; syncs
  contacts to Smaily. Consumes the contract.
- **Engine** (separate repo, the "engine team") — multi-tenant recommendation
  engine. Receives ingest, computes recommendations, runs Smaily sync/poll,
  attribution, learning. Owns the contract; the plugin tracks it.

Coordination is via the shared contract + escalation of edge cases. Routine
plugin work builds against the stable contract **without** per-step sign-off
from the engine team. Sync only when the engine changes the contract (bugfix,
new field, semantics). Escalate edge cases (these have found real engine bugs).

---

## Engine side (the contract the plugin builds against)

**Route A core: COMPLETE.** All five ingest/order endpoints aligned — batch,
D6 per-item `errors[]`, email identity, `compare_price` semantics. Contract
synced byte-identical across both repos (8 syncs, latest engine commit
`3dd5d16`).

| Engine work item | What it delivered | Status |
|---|---|---|
| W1 | per-item Layer-2 dedup canonical | synced |
| W2 | product_url/in_stock required (F3-17) | synced |
| W3 | compare_price/on_sale_until canonical (D2 Variant 1) | synced |
| W4 | email-first identity (no smaily_contact_id), batch customers, D6 reference | synced |
| W5 | batch orders, status/currency/items, D6; Bug 1 + Bug 2 fixed | synced |
| N-6 | browse §6: 9 event types, checkout_* valid, source optional | synced |
| N-7 | catalog + browse retrofit all-or-nothing -> D6 | synced |
| Final pass | request_id setup-only, §8 GDPR export cleanup | synced |

**Engine backend: ~90-95% real.** Narrow gaps, almost all engine-internal:
browse signal unconsumed (intentional, §14.2 Variant-A, post-MVP — beacon data
accumulates, influences recommendations later), mass/transactional playbooks
deferred, lift_global placeholder, one bad AI model ID. **None of these change
the contract the plugin consumes.**

**Engine frontend: ~40-50% real.** Functional: dashboards, tenant CRUD, CSV
upload wizards, integrations. **Stub: Customers browse, Orders browse,
Recommendations, Settings, Decision-log, Cron-status** — UI-only gaps over
working backends. Engine team is building these UI-first. Pilot-debug relevance:
see "Pilot go-live" below.

---

## Plugin side (our work)

### Done

- **catalog-end** — ZIP'd, live-walked. PayloadBuilder + Client + IngestQueue +
  IngestFlusher + CatalogHookHandler. (F3-16 canonical pattern.)
- **customers-end** — ZIP'd (791c00b), live-walked 10/10 against MiuMjau engine.
  CustomerPayloadBuilder + Client::ingest_customers + ApiException D6 +
  CustomerFlusher (D6 reference) + CustomerHookHandler. (F3-19 milestone.)
  Commit chain: 0fcbcd0 -> 9fabcf7 -> db3a0da -> 26a6e44 -> e4dfb91 -> 791c00b.
- **orders-end** — ZIP'd, live-walked 12/12 against MiuMjau engine. **No format
  surprises** — ordered_at Z-form (IsoDate F3-21 carried over) and the WC→enum
  status mapping both validated live (the engine rejects a raw WC status, so the
  mapping is necessary AND correct). OrderPayloadBuilder + Client::ingest_orders
  (batch 50) + OrderFlusher (D6) + OrderHookHandler (status-change wiring).
  Commit chain: 29edfe4 -> 652e16c -> 4d036cf -> a8bde99 (.3) -> this commit (.4,
  + ZIP; the .4 build-hash is this commit).
- **plugin-side N-7** — catalog-flusher D6 consolidation (the lock, now RESOLVED).
  N-7.0 extracted `AbstractD6Flusher` (shared D6 flush + errors[].index split +
  invariant) and refactored Customer/OrderFlusher onto it (byte-identical
  behavior, regression green). N-7.1 moved the catalog IngestFlusher onto the base
  (catalog all-or-nothing -> D6), updated the mock + tests to D6, and live-walked
  catalog 15/15 against MiuMjau — including `flusher_d6_split_lock_proof` (a no-SKU
  product is D6-rejected per-item and marked FAILED, the valid one SENT). The
  N-7.1 live-walk also **caught the W2 `items`->`products` wrapper drift** (the
  sync had updated the doc, not the code; the mock hid it) — fixed in Client +
  mock + ClientTest. (DECISIONS F3-22 + N-7; LESSONS §2.7.)

### Done — 3.4 browse-beacon (complete, live-walked + ZIP'd)

- **3.4 browse-beacon** — storefront telemetry → server proxy → engine
  `/api/v1/ingest/browse`. Differs from the ingest domains: client-buffered
  best-effort telemetry, NOT the Queue/Flusher pattern (intentional, F3-16
  deviation). **3.4.0 DONE** (server side): `Client::ingest_browse` (`events`
  wrapper), public `POST /beacon` proxy (`BeaconEndpoint`) with the abuse model
  — hard-404 gate (connected + `track_browsing`), per-IP + per-session
  rate-limit, server-side §6 event_type/event_id validation + field-whitelist.
  Mock browse route (D6) + unit (validate_batch, ingest_browse) + integration
  (7 proxy tests). Gates green. **NOTE/deviation to confirm:** the route is
  registered *unconditionally* and the handler 404s when disabled (not
  conditional registration) — same attack surface, but testable without
  rebuilding the REST server (which segfaults wp-env). (DECISIONS F3-24.)
  **3.4.1 DONE** (client transport): filled `RecEngineClient.track/flush/destroy`
  in `rec-engine-client.ts` — in-memory buffer, 30s batch window, consent-gated
  flush (no consent ⇒ buffer dropped, nothing sent), `navigator.sendBeacon` on
  pagehide, fetch keepalive otherwise. EventType union 8→9 (added
  `wishlist_remove`, the §2.7 drift). `captureUrlParams` (3.4.2) + `mergeIdentity`
  (3.7) still throw. 11 vitest tests. Gates green (ci:strict exit=0).
  **3.4.2 DONE** (cookies — closes the attribution loop, the cookie PRODUCER
  the 3.4.0 audit found missing): `captureUrlParams()` (campaign URL params
  smaily_vt/rec/ctx → first-party cookies, then strip the URL — cookie SAVED
  before `history.replaceState` strip so attribution can't be lost) +
  `ensureSession()` (generates the `smaily_anon_sid` v4 cookie). Cookie names +
  TTLs + URL-param names come from the engine config; cookies are SameSite=Lax,
  Secure on https, Path=/. **Cookie writes are consent-gated** (no tracking
  cookie without consent — same principle as 3.4.1 no-send; the WP Consent API
  *wiring* is 3.4.3). 7 more vitest tests (18 total). `mergeIdentity` (3.7)
  still throws.
  **3.4.3a DONE** (WP-wrapper + storefront wiring, first PHP+JS sub-PR): PHP
  `StorefrontBeacon` (wp_enqueue_scripts, gated on connected + track_browsing +
  WC active) enqueues the beacon + prints `window.smailyConnectBeacon` (config
  from engine config + page context from WC conditional tags); `beacon.ts` entry
  + `beacon-core.ts` logic wire consent to the **WP Consent API** (CookieYes etc.;
  fail-safe DENY; native `wp_listen_for_consent_change` re-run) with an
  escape-hatch (`smaily_connect_beacon_consent` PHP filter +
  `consentOverride` JS, documented in README) for non-compatible plugins, then
  on consent: ensureSession + captureUrlParams + page-view track
  (product_view/category_view/search/checkout_start/checkout_complete). Build:
  beacon bundles RecEngineClient inline → self-contained classic-loadable
  `dist/public/js/beacon.js` (no top-level import/export; vite entry swap +
  beacon-core/entry split). category_path reuses `CatalogPayloadBuilder::
  primary_category_path` (made public) so browse↔catalog correlate. Tooling
  globs broadened lib→public/js. 10 vitest + 6 integration. Gates green.
  **3.4.3b DONE** (WC cart events, JS-only): `attachCartListeners()` wires
  WC's jQuery `added_to_cart` → `cart_add` and `removed_from_cart` →
  `cart_remove`, SKU from the button's `data-product_sku`. Attached in start()
  so cart tracking is consent-gated too; no-op when jQuery is absent. Known gap:
  the single-product form-POST add-to-cart fires no JS event, so its cart_add
  isn't tracked, and a SKU-less event is skipped (best-effort, §14.2). 5 more
  vitest tests (33 client + beacon-core total). Gates green. **3.4.3 complete.**
  **3.4.4 DONE** (live-walk + ZIP): `bin/walk-3.4-browse.cjs` — **13/13 against
  the real MiuMjau engine**. Two paths: in-process REST dispatch to `/beacon`
  (full proxy→engine chain + the abuse filter on the live endpoint) + direct
  `Client::ingest_browse` (the §6 per-item behaviours the proxy 400s first).
  Proven live: all **9 event types processed** (EventType 8→9 §2.7 fix confirmed
  against the engine), anonymous vs `with_customer_match`, missing-event_id +
  invalid-event_type → engine per-item `errors[]`, dedup, and **`retroactive_bound=2`**
  (anon session events rebound to a customer once an email resolves — browse's
  hardest engine behaviour, end-to-end). Abuse on the live `/beacon`:
  101-events→400, bad-type→400, missing-id→400, rate-limit→429. ZIP includes
  `dist/public/js/beacon.js` (self-contained). **3.4 browse-beacon COMPLETE.**
  Browser render-timing (when checkout_start/complete fire) is a manual pilot
  check, NOT live-walk-covered (CLAUDE.md + below).

### Done — 3.5 backfill (complete, live-walked + ZIP'd)

- **3.5 backfill** — traverse EXISTING WC records into the engine (the live
  hooks only ingest CHANGES). One ingest path, two triggers: backfill enqueues
  into the SAME IngestQueue + AbstractD6Flusher the hooks use (DECISIONS F3-25).
  **3.5.0 DONE** (base + infra + catalog): `AbstractBackfillJob` (cursor/state/
  AS-tick/progress, resumable `WHERE id > cursor`) + `CatalogBackfillJob`
  (products → catalog.upsert, variation fan-out mirrors the hook). Enqueue +
  **inline-flush per batch** (decision (b)): progress = SENT, queue bounded. No
  freshness marker (decision (i), UPSERT-idempotent). Generalised the shared
  infra: `BackfillJobInterface` (legacy contacts BackfillJob implements it too),
  `BackfillEndpoint` SUPPORTED += products + `target_for()` (rec_engine vs
  smaily, coexist under the (job_type,target) UNIQUE key — no schema change),
  `Bootstrap::make_backfill_job()` (single dispatch for endpoint + AS tick,
  contacts gate removed), `backfill.ts` union += products. Tests prove
  resumability (resumes from cursor, not restart) + bounded queue. ci:strict
  exit=0; integration OK 56 (+5 backfill).
  **3.5.1 DONE** (customers): `CustomerBackfillJob` — `WHERE ID > cursor` on
  wp_users → customer.upsert, CustomerFlusher inline-flush. **A-filter (F3-20)
  consistent with CustomerHookHandler**: every registered user, NO role/email
  filter — the consistency is the ABSENCE of a predicate (both unfiltered), so
  neither side sends a different cohort. Test proves a subscriber/editor (non-
  customer role) is backfilled, plus resumability + bounded. Wired:
  make_backfill_job 'customers', SUPPORTED += customers, backfill.ts union.
  ci:strict exit=0; integration OK 60 (+4).
  **3.5.2 DONE** (orders, HPOS-aware): `OrderBackfillJob` — direct
  `WHERE id > cursor` against the active order table (`wc_orders` HPOS /
  `wp_posts` legacy, detected via OrderUtil; `wc_get_orders` only offers
  offset/paged, which shifts under inserts → would break the cursor). **Status
  filter matches the hook**: enumerates only mapped (sale) statuses via SQL
  `status IN (...)`, using `OrderPayloadBuilder::mapped_wc_statuses()` as the
  single source (CC-9 — can't drift from map_status). Progress denominator =
  mapped orders, not all. Test storage split: **wp-env runs WC 10.7 + HPOS, so
  the HPOS path is integration-tested; the legacy path (the pilot's WC 6.9.4
  mode) is unit-tested via the pure `table_spec` — structurally identical but
  not run against real wp_posts orders here** (CLAUDE.md "OrderBackfill"). Tests:
  resumability + bounded + status-filter (unmapped excluded) + full. ci:strict
  exit=0; integration OK 64 (+4 order backfill). **3.5.0-.2 backend complete.**
  **3.5.3a DONE** (admin UI, JS-only): reusable `BackfillPanel` (Import-now
  button + ProgressBar + status, mirrors Step2's contacts panel) — instantiates
  the already-generic `useBackfillProgress({jobType})`; progress lives in the
  hook (no reducer mirror — only contacts feeds the Step6 summary). Three panels
  (products/customers/orders, each disabled at 0 records via
  `state.env.storeTotals`) in a new "Import existing data" Card inside
  Step4Recommendations `ConnectedView` (gated on the rec-engine connection, not
  the Smaily-email one). API + hook needed no changes (3.5.0-.2 wired the job
  types). 3 vitest tests. ci:strict exit=0.
  **3.5.3b DONE** (live-walk + ZIP): `bin/walk-3.5-backfill.cjs` — **7/7 against
  the real MiuMjau engine**, all three backfill domains. Proven live: products
  + customers backfill reach **100%** (processed == total); the **order status
  filter on real HPOS data** (wp-env is WC 10.7 + HPOS) — 4 mapped of 5 orders,
  the pending one excluded (total=4); **multi-batch resumability** against the
  real engine (order job driven at batch 2 → 3 batches, cursor monotonic, never
  restarts); and **bounded queue** (pending empty after every inline flush). ZIP
  includes the new admin BackfillPanel + the storefront beacon. **3.5 backfill
  COMPLETE.** NB: the live-walk runs the HPOS order path; the LEGACY path (the
  pilot's WC 6.9.4 mode) remains unit-tested only — a pilot go-live precondition
  (above + CLAUDE.md).

### Done — 3.7 identity-merge (complete, live-walked + ZIP'd)

- **3.7 identity-merge** — bind an anonymous browse session to a known customer
  on login (§7). NOT a customer↔customer merge (the roadmap one-liner was wrong;
  v1 has no such thing — DECISIONS F3-27). Complementary to the engine's
  automatic browse-event retroactive binding (§6): covers "logs in but generates
  no email-carrying browse event after". **3.7.0 DONE**: `Client::merge_identity`
  (single §7 object, not a batch) + `IdentityHookHandler` (server-side `wp_login`
  → reads the anon-session/visitor-token cookies from $_COOKIE → posts the merge;
  api_key stays server-side, no new proxy). Dedup via user meta
  (`_smaily_rec_merged_anon_sid` — repeat logins same session don't re-hit the
  engine; a new session re-merges). 404 customer_not_found → log + skip
  (retroactive binding is the safety net). **Checkout trigger deferred** — NOT
  redundant (order ingest only stores attribution, doesn't bind history) but the
  guest's customer is auto-created by the async order ingest, absent at checkout
  → would 404; login timing is sound (A-filter ingested the user already). JS
  `mergeIdentity` stub kept (M2 platform-agnostic). Mock merge route + unit
  (Client) + 6 integration tests. ci:strict exit=0; integration OK 70 (+6).
  **3.7.1 DONE** (live-walk + ZIP): `bin/walk-3.7-identity.cjs` — **6/6 against
  the real MiuMjau engine**. Proven live: explicit merge binds an anon session
  (`browse_events_updated=2`); idempotent on repeat (`updated=0` — no
  double-binding; the engine returns `already_bound=0` on a pure repeat, an
  informational field the plugin never consumes); and the distinction from
  retroactive binding — after a browse event with the email retroactively binds
  (`retroactive_bound=2`, 3.4.4 behaviour reconfirmed), the merge is a no-op
  (`updated=0`); plus the 404 path (unknown customer → `customer_not_found`).
  ZIP'd. **3.7 identity-merge COMPLETE.**

### In progress

- **3.8 GDPR** — rec-engine personal-data rights via the WP Privacy API. Scope
  authority: `docs/DATA_MODEL_GDPR.md` (referenced, not re-derived). DECISIONS
  F3-28. **3.8.0 DONE**: `Client::customer_export` (§8 GET) / `customer_delete`
  (§9 DELETE) / `customer_opt_out` (§10 POST) + `GdprHandler` registering a WP
  Privacy **exporter** (Art 15) + **eraser** (Art 17). Export is conservative
  (engine browse_events/visitor_tokens/recommendations/email_events + customer
  record MINUS decision-logic fields like segment/RFM/engagement + plugin
  `_smaily_*` rec-meta; NOT Woo orders/totals — Woo's exporter owns that; NOT
  rec_attribution — silent). Erase is complete (engine §9 CASCADE incl.
  attribution; 404=already-gone=success; + plugin meta removed). **HPOS-safe**:
  order meta via `$order->get_meta`/`delete_meta_data` (NOT get_post_meta — would
  miss wc_orders_meta under HPOS; caught by PHPStan, a real bug). Opt-out = the
  §10 Client method only (the Smaily profiling-consent trigger + beacon two-gate
  stop is a separate later piece). Mock §8/§9/§10 routes + 3 Client unit tests +
  5 integration (incl. the WC-boundary test: `_smaily_rec_id` exported,
  `total_amount`/`line_total` NOT). **3.8.1 DONE** (live-walk + ZIP):
  `bin/walk-3.8-gdpr.cjs` — **10/10 against MiuMjau**: export surfaces engine
  browse-activity + the order `_smaily_rec_id` read from **real `wc_orders_meta`
  (HPOS)**, excludes Woo totals + decision fields + rec_attribution; opt-out
  toggles true→false; erase removes engine records + the HPOS order-meta; a
  second erase is 404-idempotent-success. The walk **caught a latent 3.8.0 bug**:
  the GDPR endpoint URLs use a `{email}` path placeholder but `Client` substituted
  via `sprintf`/`%s`, sending the literal `{email}` to the engine (404). Unit +
  mock endpoints maps had mirrored the wrong `%s`, hiding it through all green
  gates. Fixed to `str_replace('{email}',…)` (fallback templates → `{email}` too);
  mock/unit maps switched to `{email}` + the mock customer routes now **422 on a
  literal-placeholder email** so a regression fails integration. LESSONS §2.9.
  ci:strict exit=0; unit 285; integration OK 75. ZIP'd. **3.8 GDPR COMPLETE.**

### Done — 3.9 Step-4 activation (complete)

- **3.9** Step-4 activation — connect ⇒ sync all (system-decides). The four
  per-domain sync toggles (orders/customers/products/cart) were cosmetic
  (write-only options, no consumer — ingest always gated on `is_connected()`
  alone) and are **removed** from UI + types/reducers/hydrate + the POST writes;
  dead keys cleaned idempotently in `Activation::cleanup_removed_rec_feature_options()`.
  **Browse-tracking is the only Step-4 toggle** (legal-consent gate, opt-in
  default-off). **Disconnect** clears only the `smly_rec_*` connection options and
  preserves `smly_plus_rec_track_browsing`, so **re-connect restores the toggle** —
  enabled by the **mandatory hydration fix** (`EnvDetector::rec_engine_snapshot()`
  emits the saved value independent of connection; `hydrate.ts` reads it, no longer
  hardcoding `false` — also fixes a plain-reload blanking bug). PLUGIN.md
  §Step-4-4a/§6 + §15-test-5 revised; DECISIONS F3-29; README row. ci:strict exit=0
  (unit 285, JS 134); integration OK. **Phase 3 feature work done.**

### Done — 3.10.0 pilot-hardening: Event Log visibility (Layer 1)

- **3.10.0** — the diagnostics-visibility layer (production-readiness audit P2).
  New `EventsEndpoint` (`/events` + `/events/detail`) = a read-only **Event Log**
  (PLUGIN.md §13) UNION-ing both durable queues (`smly_rec_event_queue` +
  `smly_plus_event_queue`) with source/status/type filters, pagination, drill-down
  payload, and a **failed-in-24h count** for the sticky banner. No schema change —
  the queues already carry status/attempts/last_error/created_at. New React
  **Event Log** Settings tab (table + filters + drill-down modal + sticky banner),
  always available (read-only, no Save/Discard). **Backfill progress fixed** to
  report engine-confirmed `sent` + terminal `failed` (read-time count of the job's
  event-types since `started_at`) instead of records *walked* — kills the
  "1400/1400 while rows failed" lie; the panel now shows "N synced" + a failed
  notice pointing to the Event Log. Watch-item confirmed: `last_error` carries the
  HTTP code (`http_4xx`/`http_5xx`, `d6_item_error`), so 3.10.1's auto-transient
  retry can classify 4xx-vs-5xx for free. Gates: ci:strict exit=0 (unit 285, JS
  140 +6); integration OK 82 (+7, `RecEngineEventsTest`).

### Done — P6 RSS feed URL builder in Integrations (2026-06-12)

- **P6** — pilot-prep finding: the old 1.6 plugin's RSS settings tab had no
  home in the new UI. The FEED never broke (legacy `Rss` class registers
  rewrite + template whenever WC is active; all params live in the URL's query
  string — pilot's existing template URLs keep working). The tab vanished as a
  side-effect of the 2.H.3 legacy-menu hide. Rebuilt as `RssFeedSection` on the
  **Integrations** step/tab (wizard Step 5 + Settings, same component),
  **client-side + stateless** — no save path, Integrations stays info-only.
  `EnvDetector::rss_snapshot()` emits base URL (permalink-aware) + product
  categories + legacy-option prefill; null hides the section when WC inactive.
  URL builder mirrors legacy admin.js byte-for-byte. DECISIONS F3-34.
  Gates: ci:strict exit=0; integration +2 (`RssBootSnapshotTest` pins the
  legacy-classes-loaded seam the unit suite must fake). Follow-up same day:
  README.md "What's new in 2.0" + readme.txt 2.0 feature list + CHANGELOG
  gained the RSS-builder line (user-facing feature, worth surfacing); fresh
  pilot ZIP cut.

### Done — P7 upstream auto-update clobber guard: 2.1.0-beta.1 + Update URI (2026-06-12)

- **P7** — release-prep surfaced a **pilot-blocker-class risk**: upstream
  shipped its own **2.0.0** to wordpress.org (2026-06-03, same
  `smaily-connect` slug; verified live). Fork at `2.0.0-beta.1` < 2.0.0 →
  WP would offer (or with auto-updates, silently apply) upstream's 1.x-line
  package OVER the fork mid-pilot. Fix (DECISIONS **F3-35**): (1)
  **`Update URI` header** — core skips w.org updates for the plugin entirely
  (WP 5.8+; primary guard, stays until upstream merge); (2) **renumbered
  2.0.0-beta.1 → 2.1.0-beta.1** (Erkki's call) across all version literals
  (header, PHP constants, Stable tag, package.json+lock, test bootstraps,
  ConstantsTest, CHANGELOG version-note, README, MIGRATION pointer fix).
  UPSTREAM_AUDIT #128 carries the find. First GitHub **pre-release**
  (v2.1.0-beta.1-rc.1) with the pilot ZIP attached — README's Releases
  install link now resolves. NOTE: pilot ZIPs from before this fix
  (≤ db4e1cd) are vulnerable if installed on a site with auto-updates on.

### Done (code+gates) / pending (live-walks) — P8 pilot day-1: SkuResolver (2026-06-12)

- **P8 / F3-36** — pilot connect surfaced that the store has **zero SKUs** and
  old orders reference deleted products. Three surfaces were broken, catalog
  SILENTLY (pre-enqueue drop → engine never saw the store; LESSONS §2.11).
  Fix: `Support\SkuResolver` — real SKU else synthetic `wc-{id}` — used by
  CatalogPayloadBuilder (expand no longer filters; HookHandler guards
  removed), OrderPayloadBuilder, StorefrontBeacon (sku always present).
  OrderFlusher terminal-skips empty-`items[]` orders (3rd skip case).
  Deleted products: WC ZEROES the items' product reference on permanent
  deletion (empirical, WC 10.7 — initial id-survives assumption was wrong,
  the new integration test caught it) → all-deleted orders terminal-skip
  cleanly; id-survives data keys wc-{id} (unit-covered). Mock orders route
  now enforces items min-1 (the divergence that hid this). Version →
  2.1.0-beta.2.
- **Live-walks GREEN (run by Erkki, 2026-06-12 ~15:45): 3.2 catalog 15/15
  (incl. the NEW over-64-char lock-proof lever — live engine D6-rejected it,
  split held: sent 1 / failed 1), 3.3-orders 12/12, 3.4-browse 13/13.**
  Engine-write permission stays human-gated (the agent classifier correctly
  refuses agent-self-granted permission rules; walks run via `!` or a
  user-added rule).
- Also in beta.2: health-notice placement fix — `wp-header-end` marker in the
  admin wrapper, so WP relocates notices above the React app instead of into
  the React header next to the tabs (Erkki's screenshot find).
- GH pre-release **v2.1.0-beta.2-rc.1** published (build `e145607`, ZIP
  attached, deploy steps in the release notes). Supersedes beta.1-rc.1 for
  the pilot.
- Pilot redeploy steps: install the beta.2 ZIP → **catalog backfill re-run**
  (fills the silently-empty catalog) → Event Log **Retry all failed** (flusher
  rebuilds payloads fresh at flush; SKU-less orders heal in place, deleted-
  product orders leave the queue as clean skips).
- NB: wp-env carries a LIVE MiuMjau connection. An integration-suite run
  scrubs it (EnvScrub) — snapshot/restore the `smly_rec_*` options around the
  suite (done once already this way).

### Done — P9 pilot day-1 #2: abandoned-cart backlog guard (F3-37, 2026-06-12)

- **Incident:** mass abandoned-cart emails to customers minutes after the 2.x
  install. **Sender was CartBounty Pro** (third-party plugin on the pilot
  site; `cartbounty-pro` in the email links, no such email in the Smaily
  account) — most plausibly its backlog drained when the plugin swap revived
  the site's dead WP-Cron. NOT our pipeline — but ours has the identical
  flaw and is ENABLED in the pilot DB (real 1.6-era option, wizard displayed
  it honestly), one working autoresponder away from the same flood.
- **Fix:** 24h backlog guard (filterable) on `cart_updated` (epoch compare —
  the Z-form vs MySQL-format string-compare seam is a trap) + per-cart
  log-and-continue instead of abort-unmarked. `AbandonedCartGuardTest`
  (integration; fixture builds the cart table via real Lifecycle DDL).
- **Pilot actions:** (1) decide ONE abandoned-cart system — CartBounty Pro
  was already doing it; if it stays, turn OUR toggle OFF (Settings →
  WooCommerce); double reminders otherwise. (2) Confirm attribution
  on-site: CartBounty's email log timestamps vs install time.

### Done — engine-team go-live sync (2026-06-12 evening; results in docs/ENGINE_TEAM_PILOT_SYNC_RESULTS.md)

- **Tenant correction:** MiuMjau IS the pilot production tenant (no separate
  dev tenant exists). Today's walks ran against production; engine purged the
  residue. **Future walks: "Smaily Connect test" sandbox ONLY** (CLAUDE.md
  updated). Related incident, root-caused engine-side: the wp-env token
  exchange (~12:08 UTC) ROTATED the tenant's single API key → the live pilot
  store's key was silently revoked mid-day; engine migration 0036 (per-
  connection keys) fixes the class.
- **Results:** contract md5 MATCH; orders ✅ (2345 events/24h, dedup holds, the
  6.9% non-catalog item SKUs = deleted-product lines — NB this also proves the
  pilot's WC 6.9.4 does NOT zero item ids on product delete, unlike WC 10.7,
  so the resolver's id-survives path is the live one there); catalog 🟡 5783
  rows and growing (5201 wc-* + ~580 real SKUs — store is MIXED, not uniformly
  SKU-less); browse 🟡 no real traffic yet; engine logs clean post-deploy.
- **Engine fixed two of THEIR catalog-ingest bugs today** (intra-batch SKU
  dedup; emoji-split in description truncation) — the 91% error rate the
  backfill retries hit was engine-side, gone after their 14:24 UTC deploy.
- **Open items (Erkki / pilot admin):** (1) pilot store: verify connection
  alive (the key-rotation window!) — reconnect if Step 4 shows disconnected;
  (2) Event Log → Retry all failed AGAIN (pre-14:24 engine-500 rows now
  succeed; 401 rows from the key window too); (3) compare engine catalog
  count vs store product+variation count; (4) enable/verify browse tracking
  (off by default, consent-gated) — engine sees zero real browse traffic;
  (5) joint GDPR round-trip with an Erkki-issued API key; (6) sandbox setup
  token for wp-env so dev work leaves the production tenant.
- SPEC_DRAFT_BROWSE_ABANDONED_CART: engine answered all 5 open questions
  (cron sweep; 2h–24h window; 1/7d cap; custom-field trigger path; Smaily
  consent authoritative; NO qty needed → v1 needs zero plugin changes).
  Stays 🟡 on both backlogs.

### In progress — catalog-correctness series (CC.1–CC.4, 2026-06-13)

Engine brief `docs/PLUGIN_BRIEF_catalog_correctness.md` (+ design
`docs/MULTILINGUAL_DESIGN.md`, contract sync `RECENGINE_API_CONTRACT.md` §3
multilingual / §4 `language` / §620-624 catalog identity): the MiuMjau pilot
sync emitted **one catalog row per language translation** (WPML/Polylang store
each translation as its own `wp_posts` row) → duplicate synthetic SKUs the
engine can't dedupe → language-mixed recommendations; plus non-products (gift
cards, donation, language-switcher pseudo-product) reached the catalog.

**MiuMjau's actual plugin = WPML + WooCommerce Multilingual (WCML)** (Erkki
confirmed, 2026-06-13 — the brief said "Polylang/WPML" generically; the store is
WPML). `DetectorFactory` picks `WPMLAdapter` via `ICL_SITEPRESS_VERSION`. WCML
registers `product_variation` as translatable, so `wpml_object_id` (hence
`get_canonical_post_id`) resolves variations across languages **automatically** —
no attribute-matching layer needed despite MiuMjau having variable products.

**Engine-coordinated go-live order (engine team, 2026-06-13):** the canonical
SKU must cover the WHOLE SKU graph, not just catalog — **catalog AND order
items** (else the reload leaves a catalog↔orders mismatch). Plan: (1) plugin
canonical scheme to production (catalog + orders both); (2) engine WIPES the
MiuMjau SKU graph — catalog + orders/order_items + recommendations +
cadence_curves_customer + co_purchase_edges + browse_events (NOT customers /
email_events — those are email-keyed and just backfilled 30k events); (3) plugin
full re-backfill — catalog + order history (+ `{lang:value}` + customers.language);
(4) engine nightly recompute + clean re-seed. **No surgical orphan purge** — the
full wipe+re-sync supersedes the §624 manual-purge note.

**Erkki decision (2026-06-13): localization model = (B) `{lang:value}` object.**
Rationale: the expensive part (P1 translation-collapse to a canonical product
with a stable SKU) is shared by A and B; the engine is fully ready for B
(per-customer localization via `customers.language`); single-language stores
degrade gracefully to A (one-key object). Sequence CC.1 → CC.2 → CC.3 with a
checkpoint between each; CC.4 last (blocked — see below).

- **CC.1 DONE (2026-06-13)** — adapter primitive for canonical resolution.
  `DetectorInterface` gains `get_default_language()` + `get_canonical_post_id(int)`;
  implemented across all 4 adapters (Polylang `pll_default_language` +
  `pll_get_post`; WPML `wpml_default_language` + `wpml_object_id`; TranslatePress
  / SiteLocale = passthrough, one record per product). Fallback everywhere:
  unresolvable canonical → return input (**never DROP a product**). No runtime
  path calls it yet — **behaviour unchanged.** New `PolylangAdapterTest` (covers
  the real `wc-59221 LV → wc-59199 ET` shampoo case) + SiteLocale +2. Gates:
  ci:strict exit=0 (331 unit / JS 156), integration OK 108. **Behaviour-neutral.**
- **CC.2 DONE (2026-06-13)** — canonical SKU across the WHOLE graph + catalog
  enumeration collapse (P1 + P4). Scope grew from "catalog only" to "catalog +
  orders + browse" per the engine's whole-SKU-graph correction.
  - **SkuResolver is now canonical-aware** (`resolve` / `resolve_order_item` gain
    an optional `?DetectorInterface`, default lazy `DetectorFactory::create()`):
    a synthetic key collapses its id to the canonical post (`wc-{canonical_id}`);
    a real SKU is the merchant's key, untouched. Because all THREE wire surfaces
    go through SkuResolver (`CatalogPayloadBuilder:95`, `OrderPayloadBuilder:183-4`,
    `StorefrontBeacon:148`), this one change canonicalizes catalog + order items
    + browse with **zero call-site churn** — orders/browse get it for free.
  - **Catalog enumeration collapse**: backfill `enqueue_record` SKIPS a
    translation whose canonical is itself an enumerated published product
    (stateless skip-if-not-self; `processed_count` still counts every post so
    progress reaches 100%, `sent` is lower = the collapse); the live hook
    `on_save/on_stock` re-syncs the canonical; **P4** delete re-syncs the
    canonical on a translation delete (≠ marking the SKU gone), deletes only on
    the canonical's own removal. Never a silent drop (draft-canonical → the
    published post stands in; LESSONS §2.11).
  - **Variations**: WCML links `product_variation` → `get_canonical_post_id`
    resolves them automatically; no special code.
  - Detector injected into CatalogHookHandler + CatalogBackfillJob (Bootstrap
    `multilingual_detector()`); SkuResolver lazy-loads the same factory instance.
  - Tests: SkuResolver +4 (canonical/order-item), CatalogHookHandler +3
    (collapse/P4), `WPMLAdapterTest` +6 (pilot-relevant adapter), integration +2
    (real-queue collapse + draft-canonical-no-drop, stub detector; live-hook
    isolation via queue truncate; mock now records `last_catalog_skus`). Gates:
    ci:strict exit=0 (344 unit / 156 JS), integration OK 110.
  - **Still single-language content** until CC.3 — CC.2 fixes keys + collapse;
    `{lang:value}` payload is CC.3.
- **CC.3 DONE (code+gates+live-walk) (2026-06-14)** — model B
  `{lang:value}` payload. `CatalogPayloadBuilder` takes an optional
  `DetectorInterface` (Bootstrap injects it; lazy factory default).
  `build()` calls `get_translations()` once: **array form → `{lang:value}`**
  for name/description/product_url; **string form (single-language) → the
  product's own scalar fields** (model A, unchanged behaviour). description is
  tag-stripped + **clamped to 500 chars PER LANGUAGE**; empty per-language
  entries dropped; an all-empty REQUIRED field falls back to the scalar so it's
  never sent empty. SkuResolver gets the same detector for the canonical SKU.
  Tests: builder +4 (object form / per-lang clamp / empty-drop / scalar
  fallback), integration +1 (`{lang:value}` name survives the real Client→mock
  JSON round-trip; mock now records `last_catalog_names`). Gates: ci:strict
  exit=0 (348 unit / 156 JS), integration OK 111.
  **CC-8 live-walk DONE (2026-06-14)** — `bin/walk-cc3-multilingual.cjs`, **7/7
  against the "Smaily Connect test" SANDBOX**: the real engine's strict Zod
  **accepts the `{lang:value}` object** form of name/description/product_url
  (processed=1, errors=[]) AND the single-language string form (model A). A stub
  detector feeds the REAL CatalogPayloadBuilder, so it emits the same wire bytes
  the WPML/Polylang path would — the engine can't tell the source, so the wire
  contract is proven regardless of i18n plugin (no need to configure Polylang in
  wp-env). Test SKUs `LIVE-CC3-*` → excluded by the engine's `recommendable`
  flag. **NB: the dev wp-env is now connected to the SANDBOX tenant** (was
  MiuMjau-production — switched via the new token; this is the correct/safe
  state per CLAUDE.md, do not point dev at MiuMjau).
- **CC.4 — DONE: structural signal, not a filter (DECISIONS F3-38, 2026-06-14).**
  No plugin-side non-product filter (business-model decision a connector can't
  make safely; the engine's `recommendable` flag owns exclusion). Engine team
  CONFIRMED the division (commit 37a8f66) + is already consuming the signal
  (contract §3, migration 0040, `classifyRecommendable`). `CatalogPayloadBuilder`
  now always emits three top-level fields: `product_type`
  (`WC_Product::get_type()`, incl. gift-card plugins' custom types — the robust
  non-product signal), `is_virtual`, `is_downloadable` (stored, never
  auto-excluding). Builder +2 unit tests; **live-walk 9/9** (the gift-card
  `product_type: pw-gift-card` send is accepted, `processed=1 errors=[]`). Gates:
  ci:strict exit=0 (350 unit / 156 JS). Two engine return-questions answered in
  `docs/ENGINE_TEAM_recommendable_signal.md` (language-switcher `wc-49143` is NOT
  removed by CC.1–3 — needs its product_type / a post-49143 inspection to decide
  a targeted drop; MiuMjau's gift-card type string — Erkki to confirm or
  self-heal on re-backfill). **Ship the signal with the canonical re-backfill**
  so the post-reload catalog classifies on the first pass.

- **CC.5 — catalog.delete skips never-published artifacts (DECISIONS F3-39,
  2026-06-14).** Pilot Event Log showed a burst of failed `catalog.delete` rows
  (`d6_item_error field=category_path` / `product_url: String must contain at
  least 1 character(s)`) — WordPress's daily auto-draft GC purging `AUTO-DRAFT`
  products fired `before_delete_post` → `catalog.delete` with empty REQUIRED
  fields the engine 400s. Root cause: the backfill is `publish`-only but the
  delete hook filtered nothing (LESSONS §2.12). Fix: `CatalogHookHandler::
  enqueue_delete()` skips a removal whose object has blank `category_path` or
  `product_url` (`removable()` helper). Delete-only by design — the upsert path
  still surfaces an empty `category_path` on a *published* product as an intended
  merchant-data-gap signal. +2 unit tests. Pre-existing failed rows are cleared
  manually (retry can't fix them).

Already done (no work): **P2b `customers.language`** — `CustomerPayloadBuilder`
already sends ISO 639-1 from `get_user_locale()`.

- **F3-40 — trashed products kept in catalog as `in_stock=false` (2026-06-17,
  engine brief Teema 2).** ~4% of pilot order lines had no `catalog.sku` match;
  Erkki traced them to the WordPress **trash**. Trashing fires no catalog hook
  (`before_delete_post` is permanent-delete-only) and the backfill was
  `publish`-only, so trashed-but-bought products orphan the join. Fix (A+B):
  `Bootstrap` binds `wp_trash_post → on_delete_product` + `untrashed_post →
  on_save_product`; `CatalogBackfillJob` enumerates `publish`+`trash`, sending
  trashed products as `in_stock=false` (kept for the join/training; engine has no
  delete-by-key, so `catalog.delete` ≡ `in_stock=false` upsert). `is_removable`
  → shared static (reuses the F3-39 guard on both paths). Guarded a real clobber
  bug: `wp_trash_post()` also fires `save_post_product`, which re-upserted
  `in_stock=true` → `on_save_product` now skips a `trash`-status save (caught by
  the new integration test). Permanently-deleted products stay unrecoverable
  (no WC data). Gates: ci:strict exit=0 (unit 359, JS 158); integration OK 113.
  Released **`v2.1.0-beta.7-rc.1`** (GH pre-release, build `bd8b9cb`, ZIP attached);
  pilot needs a catalog re-backfill after install.
  (Brief Teema 1 = client fixes order statuses WC-side, no plugin change; Teema 3
  browse = pilot config now corrected, watching engine traffic.)
  **Live-walk 7/7 (2026-06-19, `bin/walk-f3-40-trash.cjs`, sandbox)** — closes the
  mock-only gap F3-40 shipped with (it had only ci:strict + mock-integration; per
  CLAUDE.md a catalog wire-shape change isn't done until live-walked). Proven against
  the real engine through the real code: trash → exactly one `catalog.delete` and NO
  upsert (the clobber guard live: `delete=1 upsert=0`), the engine **ACCEPTS
  `in_stock=false`** (`processed=1 errors=[]`); untrash → `catalog.upsert`, engine
  accepts `in_stock=true`; the backfill's trashed-product branch
  (`enqueue_record` → `enqueue_unavailable`) → `catalog.delete` the engine accepts.
  This also confirms the 2026-06-18 pilot log errors were **pre-existing orphan rows
  cleared on retry**, not a wire-shape bug in the fix (the happy path is engine-clean).
  (Not live-covered, by design: the `is_removable` skip of a category-less trashed
  product — WC auto-assigns "Uncategorized", fragile live — is unit-tested.)

### Done — Faas 2: legacy admin settings-page removed (F3-45, 2026-06-19)

Constraint (Erkki): **must not break the ~2000 existing installs** — only remove what's
unneeded under the new plugin or trivially replaceable with 100% functionality preserved.
A per-file audit BEFORE deleting found the legacy `Admin` class is NOT pure views:
- **Removed** (redundant + non-navigable): `admin/smaily-admin.class.php`,
  `smaily-admin-{settings,renderer,sanitizer}.class.php`, the `smaily-admin-*.php`
  settings partials, `admin/css|js/smaily-admin.*`, the credentials hook (a REST no-op),
  and the now-moot hide-legacy-menu shim.
- **Relocated** into `smaily.class.php::init_classes` (merchants lose nothing): the
  subscription widget (`widgets_init`) + the Plugins-page Settings link (→ the new UI).
- **Kept** (live dependents): `Notices` + `Notice_Registry` + `partials/notices/*` (the
  1.3.0 CF7 upgrade notice still calls `Notice_Registry::add_notice`!), `Widget`, the WC
  integrations, `Cypher`, `Options`, `Rss`, `Cart`, CF7 / Elementor.
- **No config stranded:** the new `SettingsEndpoint` reads + writes the SAME legacy option
  keys (credentials / subscriber-sync / abandoned-cart) the old page used, and the kept
  integrations read those keys. Fixed a stale `@param Admin` doc in `smaily-api.class.php`.
- Gates: **ci:strict exit=0 (unit 377, JS 158); integration OK 114** (plugin boots with the
  legacy admin gone). No wire change → no live-walk. DECISIONS F3-45.

### Done — Event Log stores the real request + engine response (Problem 3 / F3-44, 2026-06-19)

**Engine brief 2026-06-19, Problem 3:** the Event Log "Details" showed `Payload: []` —
order/catalog rows enqueue an empty payload (built fresh at send, F3-8) and only a short
`last_error` was kept, so "what did we send / what did the engine reply?" was
un-answerable, and a terminal-skip read a bare "sent" with no trace it never POSTed. Fix
(BOTH queues, per Erkki):
- **Migration 007** adds `sent_payload` + `last_response` (nullable LONGTEXT) to
  `smly_rec_event_queue` AND `smly_plus_event_queue`; a new `store_exchange()` on each
  queue writes them (separate from `mark_*` → no churn to existing overrides).
- **Rec-engine:** `AbstractD6Flusher` (single choke point) records per row
  accepted / rejected{error} / http_error; a terminal-skip → `sent_payload=null,
  last_response={outcome:"skipped"}` (exposes the silent "sent").
- **Smaily:** `Client::last_exchange()` captured in the `request()` chokepoint; the
  `Flusher` reads it via try/finally (captured even when the call throws) and stores it.
- **Never stores the Authorization header**; all rows incl. success; ~10 KB trim;
  janitor-pruned. Details modal now shows **Request sent** + **Engine response**.
- Gates: **ci:strict exit=0 (unit 377, JS 158); integration OK 114**. No wire-contract
  change (stored locally) → no live-walk needed. DECISIONS F3-44; CLAUDE.md note.

### Done — Order sync correctness: custom statuses + deleted-product lines (F3-42/F3-43, 2026-06-19)

**Engine brief 2026-06-19 (order #58922):** a guest order with a DELETED product was
marked "sent" but **never reached the engine** (no POST). Read-only investigation
cross-checked the brief against our docs/contract; two real data-loss fixes, one
already-solved (not rebuilt), one deferred:
- **F3-43 (P1, the #58922 cause):** `OrderPayloadBuilder::items()` DROPPED a line whose
  deleted product had zeroed ids → empty `items[]` → `OrderFlusher` terminal-skip →
  `mark_sent` WITHOUT POSTing (silent loss). Fix: `SkuResolver::resolve_order_item()`
  never returns '' — a zeroed-id line keys on the order-item id (`wc-oi-{item_id}`), so a
  product line is never dropped and the order is never lost. Reverses F3-36's
  drop-for-deleted; the empty-items terminal-skip now only guards a genuinely
  product-less order (shipping/fee only).
- **F3-42 (status mapping):** custom WC statuses (`label-printed`/`shipped`/…) were
  silently dropped by the 5-key allowlist (the order never reached the engine — the
  earlier Teema 1). Flipped to a DENYLIST: custom statuses default through as
  `processing`; the backfill mirrors via `status NOT IN (non_sale_wc_statuses())` (CC-9).
  **on-hold → non-sale** (reverses F3-22, per the engine team — payment not captured;
  sent when it moves to processing/completed).
- **Already solved (NOT rebuilt):** the engine's `200 {errors:[…]}` per-item →
  `mark_failed` path (F3-18 / AbstractD6Flusher). #58922 bypassed it via the terminal-skip
  `mark_sent`; the F3-43 fix makes the order POST so the existing D6 path handles any
  rejection.
- **Deferred (separate follow-up):** the brief's Problem 3 — store the real request
  payload + HTTP response in the Event Log Details (order/catalog rows enqueue an empty
  payload by design → `Payload: []`). Schema + flusher + admin-UI; not in this sub-PR.
- Gates: **ci:strict exit=0 (unit, JS 158); integration OK 113; live-walk 7/7**
  (`bin/walk-f3-43-orders.cjs`, sandbox — a custom-status order → engine accepts as
  `processing`; a deleted-product order on WC 10.7 (zeroes the ids) →
  `items:[{sku:"wc-oi-…"}]` → engine accepts). DECISIONS F3-42/F3-43; CLAUDE.md
  order-status note. Engine-side: the team will log per-item rejects to an
  `import_errors` table; a WC-completed-vs-engine data audit is suggested for other
  silently-missing orders.

### Done — Settings plugin-link opens the new UI, not the legacy view (Task 2 Faas 1, 2026-06-19)

- Pilot-reported bug: the "Settings" action link on the Plugins list opened the
  OLD module's setup view. Root cause: legacy `Smaily_Connect\Admin`
  (`admin/smaily-admin.class.php:386`) linked to `admin.php?page=smaily-connect`
  (its `$this->plugin_name` slug). That legacy menu is hidden (`remove_menu_page`,
  `Bootstrap.php:232`) but its page ROUTE still renders the legacy settings view.
  Fix: repoint to `admin.php?page=smaily-connect-settings` (the new Settings page,
  `admin/wizard.php`), which self-redirects to the wizard when
  `smly_plus_setup_completed` is false (wizard-first gate) — correct in both
  states. PHPCS exit=0; ci:strict exit=0 (unit 359, JS 158).
- **Faas 1 of the legacy cleanup.** **Faas 2 is now DONE (F3-45, below).**

### Done — engine ask #1: attribute term labels (2026-06-12 late)

- Engine's PROMPT_woo_plugin_team.md (in docs/, committed) ask #1 fixed:
  `raw_attributes` now carries term LABELS — taxonomy options (term ids) via
  `wc_get_product_terms(fields=names)`, variation slugs via `get_term_by`;
  custom attributes pass through. Unit tests rewritten (the old fake had
  already-string options — LESSONS §2.4 shape, which is how the id leak
  shipped) + a REAL WC_Product_Attribute integration test. Gates green
  (323 unit / 107 integration). **Ships in the next rc (with tomorrow's
  P10 + variation-stock hook); after deploy the pilot needs ANOTHER catalog
  backfill re-run so existing rows pick up labels.**
- NB: wp-env is now DISCONNECTED by design — the integration run scrubbed
  the MiuMjau (production!) connection and it was deliberately not restored;
  next connection = sandbox token (engine corrections doc).
- Remaining engine asks: #2 retry-queue terminal report (Erkki/pilot DB when
  queue empty), #3 browse beacon sends nothing — needs pilot-side check
  (consent gate? toggle? JS loading?), #4 catalog count compare when
  backfill done, #5 ask merchant about `live-test-cat` (104 products).

### Next — pilot-hardening sequence (in order)

**Pilot-blockers (must close before pilot), in order:**
- [x] **P5** — version-floor reconciliation (WC 6.9 / WP 6.2→6.6 / PHP 8.0; WP
  tested 7.0 restored after a context-dimming slip, LESSONS §2.10).
- [x] **3.10.0** — Event Log visibility (Layer 1, above).
- [x] **3.10.1** — failed-row recovery (Layer 2, P1): `IngestQueue::reset_failed()`
  + `EventQueue::reset_failed()` (FAILED→PENDING, reset attempts/retry-park/error) +
  `POST /events/retry` (single row / all-in-a-queue / all-in-both) that kicks the
  flushers for a prompt re-send + "Retry" (per failed row) / "Retry all failed"
  (banner) buttons in the Event Log. **Manual-only** by design (auto-retry would
  loop on a deterministic 4xx; the `http_NNN` classification is recorded for a
  future guarded auto-transient pass). ci:strict exit=0 (unit 285, JS 144);
  integration OK 85 (+3, `RecEngineEventsTest` retry cases).
- [x] **3.10.2** — proactive admin-notice (Layer 3 base, §13a): `NotificationManager`
  + a 15-min recurring health-check that raises a sticky `notice-error` on **three
  signals covering both of the pilot's sync paths**: (a) failed events > 50 in 24h
  across both queues (filterable threshold); (b) the **rec-engine** unreachable > 1h;
  (c) the **Smaily** API unreachable > 1h (contacts + email automations) — both
  down signals use the same time-based `down_since` + periodic ping, gated so an
  unconfigured store isn't reported "down". Auto-clears when the condition resolves;
  **dismissible with a 24h cooldown** (nonce'd admin-post link, no per-page nag, no
  JS). No email — that's 3.10.3, post-pilot. Pure `evaluate_signals` (10 unit tests);
  `RecEngineHealthCheckTest` (2 integration). ci:strict exit=0 (unit 295, JS 144);
  integration OK 87.
- [x] **P4** — pilot/merchant onboarding doc: `docs/INSTALL.md` (merchant-facing —
  install → setup wizard → verify → troubleshoot, integrating the 3.10.x Event Log
  / Retry / health-notice flows; documents only the *current* browser-cookie consent
  + browse toggle, NOT profiling-consent which ships with (a)). The formal
  pilot-acceptance plan (`TESTING.md`, business pass/fail) is a separate follow-up —
  it needs Erkki's acceptance criteria.

> **Consolidated deferred index: `BACKLOG.md`** — the canonical single view of
> all deferred work (priority + why + location). The sections below remain for
> narrative continuity; `BACKLOG.md` is the list to triage from.

**Post-pilot (deferred):**
- **3.10.3** — email channel (§13a email level + Notifications subpanel) via
  `wp_mail` (admin-notice base already covers proactive-in-wp-admin; email needs
  working server SMTP — recommend an SMTP plugin in the doc).
- ~~Queue janitor (prune `sent`/`failed` rows + index `created_at`)~~ — DONE
  2026-06-11, FABLE_AUDIT fix F6 / DECISIONS F3-33 (pulled forward pre-pilot:
  daily AS prune, sent 30d / failed 90d filterable, pending never; migration
  006 `idx_created_at`). (~~GCM encryption~~ — DONE 2026-06-11, FABLE_AUDIT
  fix F3 / DECISIONS F3-32: Cypher v2 GCM + upgrade re-encryption.)
- ~~**WP 7.0 env-matrix verification**~~ — **RESOLVED 2026-06-11**, with a
  twist: instead of a two-env matrix, Erkki moved the integration BASELINE to
  WP 7.0 (`.wp-env.json` core → `wordpress.org/wordpress-7.0.zip`; the WP 6.9.4
  baseline was an interim step). **Full suite 99/99 green on WP 7.0** (WC 10.7 +
  Polylang). One real 7.0 finding: the heavier core exhausted the 128M phpunit
  memory limit during in-process REST dispatch → the runner now passes
  `-d memory_limit=512M`. The PILOT still runs the old stack — the
  pilot-faithful override recipe (WC 6.9.4 + PHP 8.1) is documented in
  CLAUDE.md ("Integration baseline is WP 7.0…").

**After pilot-hardening — (a) Smaily profiling-consent wiring (in progress):**
Spec: `SMAILY_PROFILING_CONSENT_SPEC.md`; design: DECISIONS F3-31 (OPT-OUT model,
default-on). Sub-PRs:
- [x] **(a).0** — probe-first + Client read/write + enforcement core. A live probe
  against the real Smaily API confirmed write (`upsert_subscribers`, custom fields
  auto-create → `101`) + read-back (`GET /api/contact.php?email=` → `is_unsubscribed`
  + `smaily_rec_profiling`); caught that the spec had *assumed* read-back (the one
  real risk). Built `Client::get_contact_consent` + `write_profiling_consent` +
  `ProfilingConsent` (pure opt-out rule `is_allowed`, cached read-back daily TTL,
  WP opt-out → Smaily write + cache + engine §10 opt-out, fail-open). 12 unit tests.
- [x] **(a).1** — beacon two-gate + retroactive-bind respect. The `BeaconEndpoint`
  proxy now drops browse events carrying an opted-out contact's email before
  forwarding (anon events — no email — pass on the cookie gate alone); all-dropped
  → `processed:0` without calling the engine. Drop is a **conscious drop** (opt-out
  working, not an error): aggregated into a 24h counter (`smly_profiling_dropped_24h`,
  for a future surface) + logged **once per batch** (never per event → no flood).
  Plus: `IdentityHookHandler` **skips `identity.merge` for an opted-out contact** —
  so their anon browse history is NOT retroactively bound to their profile (respect
  the opt-out backwards; the anon events stay unattributed). Wired via
  `Bootstrap::profiling_consent()`. Tests: +2 beacon (drop one / all-dropped) + 1
  identity (no retroactive bind). ci:strict exit=0 (unit 307, JS 144); integration
  OK 90 (+3).
- [x] **(a).2** — WP opt-out UX + live-walk. **WP UX:** `ProfilingConsentAccount`
  adds a **WooCommerce My Account → privacy toggle** ("use my data for personalised
  recommendations") — shopper-facing per spec §10, the working opt-out the model
  requires. The toggle's state mirrors the read-back (shows a Smaily-side opt-out
  too); checked→`opt_in`, unchecked→`opt_out`. **Live-walk** `bin/walk-a-profiling.cjs`
  against real Smaily + the engine, via the WIRED code: **9/10** — write→read-back
  round-trip ✓, enforcement rule ✓, may_profile ✓, opt-out→Smaily=0 ✓, **beacon-stop
  (drop) ✓**, opt-in restore ✓. The **§10 step is env-blocked**: the dev connection
  is the stale integration fixture (`re-fixture.test`, unreachable), not real
  MiuMjau — re-run after a real setup-token re-exchange; §10 itself is already
  3.8-live-walked (10/10) + integration-proven. 3 account unit tests. ci:strict
  exit=0 (unit 310); integration OK 90.
- **TODO** — explicit opt-in if AKI tightens (`is_allowed()` is invertible); privacy
  policy must mention profiling (Erkki / docs).

---

## 🎯 PLUGIN-SIDE PILOT-FEATURE-COMPLETE (2026-06-09)

All plugin-side feature + hardening work for the pilot is done: Phase 3
(catalog/customers/orders/browse ingest + backfill + identity-merge + GDPR +
Step-4 activation), pilot-hardening (P5 version floors, 3.10.0–3.10.2 Event Log /
Retry / health notices, P4 `INSTALL.md`, legacy-WC verification), and (a) Smaily
profiling-consent ((a).0 enforcement + (a).1 beacon two-gate + (a).2 opt-out UX +
live-walk). Feature-complete ZIP cut at this commit.

**What remains before pilot go-live (NOT plugin feature code):**
- **Erkki / business:** ~~`TESTING.md` pilot-acceptance plan~~ — **DONE 2026-06-11**:
  `docs/TESTING.md` written from Erkki's input (two gating dimensions — technical
  stability + merchant experience; business metrics tracked-not-gated; logistics:
  4–6 wk, real data from pilot start, twice-weekly→weekly check-ins, go/no-go at
  pilot end). Remaining business items: the (a) TODOs above (profiling opt-in if
  AKI tightens; privacy-policy profiling mention; the fail-open GDPR-window review).
- **Optional:** §10 profiling live-walk re-run (proven by 3.8 + integration —
  belt-and-suspenders only; needs a fresh real-MiuMjau token).
- **Engine team:** the engine-side frontend debug views (Customers/Orders browse)
  — see Engine side below.
- **Manual / pilot verification (not machine-testable):** the browse render-moment
  (page-view fires on the right page), and live consent gating (CookieYes actually
  suppresses the beacon). See "Known deferred items".
- ~~**Pre-pilot pin:** the WP 7.0 env-matrix verification~~ — DONE 2026-06-11
  (baseline moved to WP 7.0, suite 99/99; see Post-pilot section).

### Waiting / lock conditions

- **catalog-flusher N-7 D6 consolidation — RESOLVED (N-7.1, 2026-06-06).** The
  catalog flusher now extends `AbstractD6Flusher`; an engine per-item rejection
  marks that row FAILED, not SENT (silent-loss class closed). Proven against the
  real engine by the catalog live-walk (`flusher_d6_split_lock_proof`: sent:1,
  failed:1). No remaining lock conditions on the plugin side. (DECISIONS F3-22.)

### Roadmap (Phase 3 remaining)

- ~~**3.4** browse-beacon~~ — DONE (above). NB §14.2: the engine consumes browse
  post-MVP — pilot expectation is "collects data, improves recommendations
  later, not now".
- ~~**3.5** backfill~~ — DONE (above): catalog/customers/orders backfill,
  cursor-resumable, inline-flush bounded, live-walked 7/7. (Legacy order path =
  pilot precondition.)
- ~~**3.6** beacon~~ — REMOVED (not a separate sub-PR). The README feature table
  split "Browse ingest" + "Beacon (browse tracking)" as two items; 3.4
  browse-beacon shipped BOTH (3.4.0 = Client::ingest_browse + /beacon proxy =
  browse ingest; 3.4.1-.3 = the client beacon track/flush/cookies/consent/WC
  events = beacon tracking). So "3.6 beacon" duplicated 3.4. (A storefront
  recommendation-render widget is a separate FUTURE epic — never numbered here.)
- ~~**3.7** identity-merge~~ — DONE (above): anon-session → known-customer
  binding on login (NOT a customer↔customer merge — v1 has none); live-walked
  6/6. (DECISIONS F3-27.)
- ~~**3.8** GDPR (WP Privacy API)~~ — DONE (above): exporter (Art 15) + eraser
  (Art 17) + opt-out (§10), HPOS-safe order-meta; live-walked 10/10. The 3.8.1
  walk caught a latent `{email}`-placeholder substitution bug (DECISIONS F3-28.6,
  LESSONS §2.9).
- ~~**3.9** Step-4 activation~~ — DONE (above): connect ⇒ sync all
  (system-decides); per-domain sync toggles removed, browse-tracking the only
  Step-4 toggle (consent-gated, preserved across disconnect/re-connect via the
  mandatory hydration fix). DECISIONS F3-29. **Phase 3 feature work complete.**

---

## Pilot go-live — both sides must be ready

Pilot does NOT go live until all of these hold. No deadline pressure (D5).

**Plugin side:**
- [x] catalog-end ZIP'd + live-walked
- [x] customers-end ZIP'd + live-walked
- [x] orders-end ZIP'd + live-walked (12/12)
- [x] catalog-flusher N-7 D6-fix (lock RESOLVED — N-7.1, catalog live-walk 15/15)
- [x] **order-backfill LEGACY path verified against a real legacy WC env**
  (RESOLVED 2026-06-09). Stood up a `.wp-env.override.json` pinning **WC 6.9.4 +
  PHP 8.1** (WP core 6.9.4); reset the carried-over HPOS options so
  `is_hpos()=false`, `wc_orders` absent → a faithful WC 6.9.4 legacy store
  (orders in `wp_posts`). `RecEngineOrderBackfillTest` ran the legacy
  `table_spec(false)` path — `WHERE post_type='shop_order' AND post_status IN(…)
  AND ID > cursor` against the real WC 6.x posts schema (4 tests, 14 assertions),
  and the **FULL integration suite passed 75/75 on legacy** (no other path has a
  hidden HPOS assumption). PHP pin to 8.1 was used (WC 6.9.4 on PHP 8.3 risks
  deprecations); `OrderBackfillJob::is_hpos()` is correctly guarded with
  `class_exists(OrderUtil)` so it can't fatal on a pre-HPOS WC. (Harness note: the
  mock-server teardown uses the `SIGTERM` constant, undefined without `pcntl` on
  the PHP 8.1 image → the documented exit-255 wrapper quirk; tests pass.)

**Engine side:**
- [x] backend (90-95%, gaps engine-internal)
- [ ] **frontend debug views** — Customers browse + Orders browse (at minimum)
  functional, so a pilot problem ("X didn't sync") can be seen in the UI rather
  than debugged DB-direct. Engine team building UI-first.

A working backend the team can't see into is debug-blindness in pilot. Both
sides ready = go-live.

---

## Known deferred items (tracked, not blocking)

> Consolidated with priority in `BACKLOG.md` (🟢 / 🔵 tiers).

- N-7 EVENT_* constant location asymmetry (catalog `EVENT_CATALOG_*` on
  CatalogHookHandler, customer/order on their Flusher) — still asymmetric after
  N-7. N-7 chose an **abstract base** (`AbstractD6Flusher`), NOT a monolithic
  dispatcher, so each flusher keeps its own constants/hook/group; the "unify under
  a dispatcher" premise no longer applies. Cosmetic only — defer or drop.
- Flaky useBackfillProgress test (fake-timers race) — fix with deterministic
  timer mocking.
- **Browse browser-timing — manual pilot verification (not live-walk-covered).**
  The 3.4 live-walk proves the engine contract (proxy→engine + abuse + all 9
  types) but a server-side walk can't observe the browser MOMENT a page-view
  fires (checkout_start on the checkout page, checkout_complete on
  order-received, product_view on a product page). The PHP page-type detection
  (`StorefrontBeacon::page_context`) can't be driven in the integration harness
  (no `WP_UnitTestCase`/`go_to()`); JS mapping is vitest-tested. So confirm the
  render moment manually during the pilot (or a future Chromium E2E — not built,
  YAGNI, low risk). See CLAUDE.md "Browse browser-timing".
- **Browse-event identity — RESOLVED (F3-49, 2026-07-03).** The beacon carried only
  `session_id`, so contract §6 per-event identity-resolution + retroactive-binding never
  fired from our stream and the async order-attribution path-3 was inert. Engine team
  confirmed browse does NOT feed attribution — order `smaily_rec_id` + email-click drive
  the `direct`/`exact_later`/`indirect_*` mix; browse would at best give the soft
  `assisted_view`. So the CLIENT still never adds `smaily_rec_id`/`customer_email`
  (data-minimization unchanged — PRO-1389, 2026-07-21, adds the one sanctioned
  SERVER-side exception: `/relay` attaches `customer_email` for a resolved,
  non-opted-out logged-in session, on the outbound engine request only), and browse
  NOW carries the opaque `smaily_visitor_token` (omit-on-empty)
  for the engine's future **cold-start personalization** binding (the engine binds the
  browse row via it; ingest already accepts the field). Profiling opt-out on the
  token/external_id path is engine-side (server-enforced 2026-07-03) — the plugin's
  email `ProfilingConsent` gate stays the first filter. Guest-browse-session-only is an
  accepted v1 limitation. See DECISIONS F3-49 + the "Browse browser-timing" item above.
- GDPR export omits rec_attribution — engine-side Art 15 legal review (not a
  contract issue for the plugin).
- F3-19 guest-customer flusher concern: RESOLVED by W5 — engine auto-creates the
  customer from the order's customer_email; OrderFlusher is order_id-based (guest
  orders have an order_id), so no payload-carried path needed.

---

## Future / backlog (not scheduled)

Feature ideas worth keeping, distinct from "Known deferred items" above (those
are tracked technical debt). These are NOT scheduled — build only when a real
need arrives (YAGNI).

- **Browse 0-events on CookieYes — RESOLVED (v3.3.2, F3-50, 2026-07-03).** The beacon is
  **fail-closed** on the WP Consent API (`detectConsent()` sends only when
  `window.wp_has_consent(category) === true`). MiuMjau saw 0 `/api/v1/ingest/browse` (while
  ping/orders/catalog were fine) because `window.wp_has_consent` was **undefined** live.
  **Root cause (corrected):** CookieYes DOES integrate the WP Consent API — but only when the
  free companion **"WP Consent API" plugin** (`wp-consent-api`) is installed (it defines
  `wp_has_consent`; CookieYes registers into it, `Advertisement`→`marketing`). MiuMjau just
  lacked that plugin. **3.3.1 mis-fix reverted:** it shipped a CookieYes-specific cookie-parser
  on the wrong assumption "CookieYes can't do the API" — Erkki caught it (per-vendor code =
  maintenance debt; CookieYes's docs prove it supports the standard). **3.3.2:** revert the
  vendor code (browse consent stays purely on WP Consent API + `consentOverride` hatch) + a
  `NotificationManager` admin advisory (browse on + connected + no `wp_has_consent` → "install
  the free WP Consent API plugin"). MiuMjau fix = install `wp-consent-api` (wp-admin, no file
  access). DECISIONS F3-50 / LESSONS §2.15. NB: there is NO `smaily_connect_beacon_consent` PHP
  filter (only JS `consentOverride` + `smaily_connect_beacon_consent_category`).
