# Smaily Connect — Install & Setup

> **The full install / setup / troubleshooting guide is now the merchant
> documentation site: [`docs/site/index.html`](site/index.html), published at
> <https://smaily.com/connect-woo/>.**
> It is bilingual (EN/ET) and mirrors the Shopify docs at
> `connect.smaily.com/docs`. Open the file in a browser, or host it anywhere —
> it is a single self-contained page (no build step, no external dependencies).

This stub remains because other docs (README, STATUS, INDEX) link to
`docs/INSTALL.md`. The install walkthrough, the 6-step wizard, "verify it's
working", and the Event-Log troubleshooting flow all live in the docs site now —
kept in one place so they can't drift.

## Getting the plugin

Install it from wordpress.org like any other plugin: `Plugins → Add New Plugin`,
search for **Smaily Connect**, `Install Now`, `Activate`. The slug is
`smaily-connect` and the current release is **3.12.1**. WP-CLI:
`wp plugin install smaily-connect --activate`.

The release ZIP attached to each tag in
[`sendsmaily/smaily-wordpress-plugin`](https://github.com/sendsmaily/smaily-wordpress-plugin)
is the same package, for staging sites and environments that install from a
file. Then continue with the setup walkthrough in the merchant guide below.

## Requirements (quick reference)

| Component | Minimum | Tested up to |
|-----------|---------|--------------|
| WordPress | 6.6 | 7.1 |
| WooCommerce | 6.9 | 10.7 |
| PHP | 8.0 | 8.3 |
| HTTPS | required (Smaily + recommendations APIs are HTTPS-only) | — |
| Smaily account | active, with API access | — |

## Where to look

| You want… | Go to |
|---|---|
| Install + setup + verify + troubleshoot (merchant guide) | **[`docs/site/index.html`](site/index.html)** → *Getting started* / *Error messages & fixes* |
| Pilot-acceptance test plan (business pass/fail criteria) | [`TESTING.md`](TESTING.md) |
| Upgrading an existing site from 2.0.0 (the previous plugin line) | [`MIGRATION.md`](MIGRATION.md) |
| Architecture & rationale | [`DECISIONS.md`](DECISIONS.md) |
