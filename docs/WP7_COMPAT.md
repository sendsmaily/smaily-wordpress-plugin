# WP7_COMPAT.md — WordPress 7 compatibility and strategic opportunities

WordPress 7.0 "Armstrong" was released **on 20 May 2026** — the largest core release
since 5.0 (2018). This document maps out what the Smaily Connect plugin needs to do
**now** (compatibility) and **strategically later** (new AI APIs that fit the plugin's
ecosystem directly).

---

## What WP 7 brings — three main things

**1. PHP 7.4 minimum** (was 7.2/7.3). The plugin is already on PHP 8.3, so **no
concern here**. WP 6.9 stays as a branch for older PHP versions.

**2. Real-time collaboration** in the block editor (Yjs-based) plus the first real
admin redesign in 10+ years (new colors, typography, icons). Visual impact on the
plugin's Tailwind-styled wizard and Settings — needs an audit.

**3. AI infrastructure in core** — this is the **strategically interesting** part.
Three related APIs:
- **Connectors API** (Settings → Connectors) — platform-level credential storage and
  provider management for external services. Built around `php-ai-client`.
- **Abilities API** — plugins register **typed actions** that AI agents can discover
  and call.
- **MCP Adapter** — exposes the whole stack to Model Context Protocol tools (Claude
  Code, Cursor, etc.).

Note: Connectors, Abilities, and MCP are **experimental** in WP 7. The right strategy
for plugin teams is **opt-in adoption**, not staking the whole product on the current
UI contract.

---

## What to do in the plugin now (don't interrupt Phase 3)

These are **small**, no need to interrupt Phase 3 rec-engine work:

1. ~~**Extend the wp-env matrix**~~ — **DONE 2026-06-11**, as a baseline move
   rather than a two-env matrix: `.wp-env.json` core is now WP 7.0 (Erkki's
   call — the WP 6.9.4 baseline was an interim step). Full integration suite
   99/99 on WP 7.0 + WC 10.7. Finding: WP 7.0's heavier core exhausted the
   128M phpunit memory limit in REST dispatch → the runner now passes
   `php -d memory_limit=512M`. The pilot's old stack (WC 6.9.4 + PHP 8.1)
   remains reproducible via the override recipe in CLAUDE.md.

2. **Visual audit** — the agent's Chromium walks check the wizard and Settings
   against the WP 7 admin chrome. There will likely be **small shifts** (focus ring
   colors, link colors, font differences). Brand pink and dark navy should work on
   the new palette, but verify.

3. **Plugin header** — once the compat check is green:
   - `Tested up to: 7.0`
   - `Requires at least: 6.6` (or whatever the current minimum is)
   - `Requires PHP: 7.4`

4. **WC HPOS + WP 7** — verify that HPOS works on WP 7. WC 10.7 should be fine, but
   edge cases are edge cases — a Chromium walk for WC product creation and order
   creation covers it.

5. **Smaily Landing Pages Gutenberg block** — if the plugin ships one, verify it
   works with real-time collab (two users in the editor at once). Probably fine
   (block API didn't change fundamentally), but note it.

**Sub-PR scope:** all five together in one small sub-PR (e.g. 3.x.x WP 7 compat).
NOT inside Phase 3 as a standalone sub-PR — fits at the end of Phase 3 or the start
of Phase 4.

---

## Strategic opportunity — Abilities API + Connectors (Phase 4)

**This is the more important half of the document.** The WP 7 AI APIs **fit your
project directly** — Smaily and the rec engine are **exactly what these APIs were
designed to unify**.

### Opportunity 1 — Smaily and rec engine as Connectors

The WP 7 Connectors hub is **centralized credential management** for external
services. The current plugin owns the whole credential UI (subdomain + username +
password for Smaily, setup-token → api_key for the rec engine). WP 7 Connectors can
**take that burden over**:

- The user configures everything under `Settings → Connectors` **once**
- The plugin reads credentials through the Connectors API, not from its own
  `wp_options` table
- The UX aligns with the rest of WP, the user doesn't have to learn a separate
  credential UI per plugin

**Risk:** the Connectors API is **experimental** in WP 7 — the UI contract may
change. The right step isn't to migrate now, but to **prepare**: plugin-side
credential logic should already be **isolated** (`Credentials.php`,
`RecEngineSettings.php` value objects), so later swapping in
`WP_Connector::get('smaily')` is easy. **You're already building it right** — this is
forward-looking architecture.

### Opportunity 2 — Abilities API as a frame for the rec engine ⭐

**This is the most interesting one.** The Abilities API lets a plugin register
**typed actions** that AI agents (Claude, GPT, a local WP AI) can **discover and
call** through a standard interface.

Smaily Connect would have natural abilities:
- `smaily.subscribe_user` — add a contact to a list
- `smaily.send_email` — send a transactional email
- `smaily.list_workflows` — list automations
- `recengine.get_recommendations` — fetch recommendations for a user
- `recengine.track_event` — log a browse or purchase event
- `recengine.merge_identity` — merge anonymous → known user

**Strategic impact:** if Smaily Connect offers these abilities, then **any AI tool**
in the WordPress ecosystem (a merchant's AI assistant, Claude doing site automation,
third-party plugins) can **call Smaily and the rec engine through a standard
interface**. Not just your plugin → the engine, but **the whole ecosystem → Smaily**.

**Competition:** Mailchimp, Klaviyo, Brevo, and the other major email platforms
haven't rushed to add WP 7 Abilities support. **If Smaily is the first Estonian /
Nordic email platform with Abilities support**, that's a **head start to claim**
(developer mindshare, "AI-ready" marketing positioning, MCP-tool compatibility).
A Phase 4 strategic priority.

### Opportunity 3 — MCP Adapter

An extension of the Abilities API to AI tools (Claude Code, Cursor, etc.) via the
Model Context Protocol. **Depends on Abilities being in place** — once Abilities are
registered, the MCP Adapter exposes them automatically to MCP tools. Small extra
investment, large value (Claude/Cursor can call Smaily directly).

**The automatic next step after Abilities (Opportunity 2).**

---

## What NOT to change now

- **Wizard credential logic** — the Connectors API is experimental, wait for stable
- **Admin UI components** — the admin redesign may cause color conflicts, but wait
  for real user feedback. Tailwind is flexible enough — likely shifts, not breakage.
- **Block editor integration rewrites** — the block API didn't change fundamentally,
  only a real-time collab layer was added on top
- **Phase 3 rec-engine work in the Abilities direction** — Abilities come after the
  rec engine is done, not during

---

## Concrete timeline

| When | Activity | Size |
|------|----------|------|
| Alongside Phase 3 (now) | wp-env matrix WP 7 + visual audit + plugin header | ~1 day |
| After Phase 3 ends | WC HPOS + WP 7 sanity check + block editor collab test | ~1 day |
| **Phase 4 start** | **Abilities API + MCP Adapter strategic design** | ~1-2 weeks |
| Phase 4 mid | Migrate to Connectors API once stable | ~1 week |

**Phase 4 strategic priority:** the Abilities API. Not just technical compatibility,
but **positioning in the AI-driven WordPress ecosystem**. Smaily as the first email
platform with Abilities support could be **the biggest marketing win** for Smaily in
years — especially if the WP 7 AI direction continues in WP 7.1, 7.2 (which is
likely).

---

## Sources

- WordPress 7.0 "Armstrong" — 20 May 2026
- Make WordPress Core dev note: Connectors API (March 2026)
- Make WordPress Core dev note: Client-Side Abilities API (March 2026)
- WordPress 7.0 Developer Guide (Nandann Creative, March 2026)

See also: `LESSONS.md` (general lessons on building with an AI agent),
`RECENGINE_TODO.md` (engine-side status), `RECENGINE_API_CONTRACT.md` (plugin ↔
engine contract).
