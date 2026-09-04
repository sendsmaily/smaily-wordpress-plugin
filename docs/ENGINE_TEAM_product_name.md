# Engine team note — product name: "Smaily Campaign Intelligence"

**Date:** 2026-06-15 (supersedes the 2026-06-14 "Smaily Intelligence Engine" note)
**From:** recommendation-engine team (Erkki)
**Status:** naming decision — applied in the WooCommerce connector's UI / README / readme.txt copy (2026-06-15)

## The name

The recommendation engine product is now officially **Smaily Campaign Intelligence**
(this supersedes the short-lived "Smaily Intelligence Engine" name).

Use this name wherever the product is referred to in **user-facing** plugin surfaces — the connection/settings screen title, onboarding text, the integration's display name, store-admin help text, and docs/READMEs that a merchant or operator reads.

| Use | Form |
|---|---|
| Full product name (where the product is described) | **Smaily Campaign Intelligence** |
| Short (tabs, labels, after first mention) | **Campaign Intelligence** |
| Generic shorthand in a sentence | the engine (lowercase, e.g. "…the engine learns from your data") |
| Sidebar / logo sub-line (engine console) | "Personalised recommendations" (tagline may change — see engine `docs/MARKETING_COPY.md`) |

## What to stop calling it

Avoid these older / informal names in user-facing text:
- ~~"Smaily Intelligence Engine"~~ (the 2026-06-14 name, superseded by this note)
- ~~"Smaily Recs"~~ / ~~"Smaily Recommendations"~~
- ~~"the recommendation engine"~~ as a *proper name* (fine as a generic description in a sentence, e.g. "…sends data to the engine", but the product's name is "Smaily Campaign Intelligence")
- ~~internal codenames~~ (e.g. `rec-engine`)

## What does NOT change

- **API contract, endpoints, field names, headers** — unchanged. This is a display-name/branding decision only. `RECENGINE_API_CONTRACT.md`, the `/api/v1/*` paths, `X-Engine-Version`, etc. stay exactly as documented.
- Package names (`recengine-client`, `recengine-shared`) — internal, no need to rename.

## Why this note lives here

So both connectors (WooCommerce `connect`, Shopify `shopify-connect`) converge on one product name across all three repos. Pick it up whenever you next touch UI strings — no migration, no deadline.
