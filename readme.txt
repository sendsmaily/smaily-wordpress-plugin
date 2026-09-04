=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 8.0
Requires at least: 6.6
Tested up to: 7.0
WC requires at least: 6.9
WC tested up to: 10.7
Stable tag: 3.11.2
License: GPLv3 or later

Email marketing, automations and personalized product recommendations for WordPress, WooCommerce, Contact Form 7 and Elementor — powered by Smaily.

== Description ==

**Smaily Connect – The Only Email Marketing Plugin You Need!**

Transform your **WordPress website, WooCommerce store, Contact Form 7 and Elementor** into an **email marketing powerhouse** with Smaily – the all-in-one plugin designed to **automate your marketing, grow your audience, and drive more sales effortlessly**.

**Why Smaily Connect?**

**Turn Visitors into Subscribers** – Capture leads from **every touchpoint** – your website, WooCommerce store, and contact forms – all in one seamless flow.

**Automate Like a Pro** – Send high-converting emails effortlessly: welcome emails and abandoned cart reminders – **without lifting a finger**.

**Smart Form Integration** – Sync your **Contact Form 7** submissions directly to your Smaily lists for a frictionless email collection experience.

**Elementor Integration** – Build beautiful newsletter sign-up forms right inside Elementor using our dedicated widget!

**Smarter Email Campaigns** – Segment your audience and send **relevant offers, tailored product updates, and engaging content** that keeps subscribers interested and active.

**Personalized Product Recommendations** – Connect your WooCommerce store to Smaily Campaign Intelligence and use shopper-specific product suggestions in your email campaigns.

**Easy, Fast & Code-Free Setup** – No tech skills needed! A **guided setup wizard** walks you through connecting your Smaily account and configuring every integration.

= What's new in 2.0 =

Version 2.0 is a major update built alongside the proven 1.x feature set:

* **Setup wizard** – a guided, step-by-step first-run experience: connect your Smaily account, configure subscriber sync, WooCommerce automations, and form integrations.
* **Modern admin** – a redesigned, mobile-friendly settings interface.
* **Campaign Intelligence (WooCommerce)** – optionally connect your store to Smaily Campaign Intelligence. The plugin syncs your product catalog, customers and orders so it can generate personalized product recommendations for your email campaigns.
* **Browse tracking (opt-in)** – an optional storefront beacon records browsing activity (product views, searches, cart events) to improve recommendations. It is **off by default**, requires the site admin to enable it, and only runs for shoppers who have given cookie consent (WP Consent API compatible, e.g. CookieYes).
* **Privacy built in** – integrates with the WordPress Privacy tools (personal data export and erasure), and shoppers can opt out of recommendation profiling from their WooCommerce My Account page.
* **Reliability you can see** – background work runs on durable queues (Action Scheduler); an Event Log shows every sync event, failed items can be retried from the UI, and health notices warn you proactively when a connected service is unreachable.
* **Multilingual-aware** – language detection works with Polylang, WPML and TranslatePress for routing subscribers to the right lists and automations.
* **Product RSS feed** – load store products straight into your Smaily email templates. The familiar 1.x feed is unchanged; its URL builder now lives on the Integrations tab, and existing feed URLs keep working.

Existing installs upgrade in place — your settings, credentials and connections are preserved. If you have already completed the setup wizard, the new behaviour (including the cron-safe contact-language and consent sync) is active immediately on upgrade with no re-setup; an install that has never finished the wizard keeps its legacy live sync until it does.

= Documentation & Support =

For documentation, feature requests, and support, visit our [Help Center](https://smaily.com/help/user-manuals/).

= External services =

This plugin uses [Smaily Public API](https://smaily.com/help/api/) to communicate with your Smaily account. This is needed to establish a connection and transfer information between your WordPress site and your Smaily account. The plugin uses the API for following functionality:

- validating Smaily account connection with API key
- listing available automation workflows
- triggering automation workflows on form submissions and during sending abandoned cart reminders
- managing user subscription status during subscriber synchronization
- updating user subscription status when unsubscribing from newsletters
- reading and writing the shopper's recommendation-profiling consent preference

If you connect the optional **Smaily Campaign Intelligence** (a Smaily-operated service; the connection is established with a one-time setup token issued for your account), the plugin additionally sends the following WooCommerce data to it so it can compute personalized product recommendations:

- product catalog data (titles, prices, categories, stock status, product URLs)
- customer records (email address, name, registration date)
- order data (order status, totals, purchased items)
- browsing events (product views, searches, cart and checkout events) — **only** when the site admin has enabled browse tracking **and** the shopper has given cookie consent; shoppers who have opted out of profiling are excluded
- personal-data export, erasure and profiling opt-out requests, so WordPress Privacy tools and shopper preferences are honored on the engine side as well

You can manage how much information is shared between your WordPress site and Smaily account by configuring the plugin settings.

Privacy Policy: [Smaily Privacy Policy](https://smaily.com/privacy-policy/)
Terms of Service: [Smaily Terms of Service](https://smaily.com/terms-of-service/)

= Contribute =

Contribute to the development via [GitHub](https://github.com/sendsmaily/smaily-wordpress-plugin). We welcome new issues and pull requests.

== Installation ==

1. Upload the plugin files to your site's `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open the Smaily Connect admin page and follow the setup wizard to connect your Smaily account and configure the integrations.

== Changelog ==

Only releases from 3.0.0 onward are listed here. The complete version history, including the 1.x and 2.x releases, is published at https://github.com/sendsmaily/smaily-wordpress-plugin/releases

= 3.11.2 =
* Fixed: on a store where Smaily Campaign Intelligence uses its own attribution cookie names, orders now carry their recommendation attribution again — the checkout was looking those cookies up under the default names only, so no order recorded which recommendation it came from.
* Fixed: a malformed recommendation attribution value (visitor token, campaign context, session) is now left off the order sent to Smaily Campaign Intelligence instead of being sent as if it were real attribution.
* Improved: the setup wizard can now test the connection of a Smaily account your store already has stored, without you retyping its API password.
* Improved: on a store upgrading from an older plugin version, the daily contact catch-up sync now waits until the setup wizard has been confirmed, so it cannot run against a half-configured store. Live contact syncing (signups, profile changes, checkout opt-in) keeps working throughout.
* Improved: the release package is now built and verified automatically for every release, and no longer carries developer-only source maps or TypeScript sources.
* Improved: the migration guide now describes the 2.0.0 to 3.11.2 upgrade merchants actually take through the WordPress updater.
* Improved: the setup wizard's Campaign Intelligence step now explains what the add-on does, what it costs and how to activate it.

= 3.11.1 =
* Fixed: the order-completion signal sent to Smaily Campaign Intelligence no longer depends on the shopper keeping the order-received page open. It is sent as soon as the order is confirmed, so completed orders are no longer under-counted when recommendations are computed.
* Fixed: email-link attribution values arriving in a landing URL are now checked in the browser before they are stored, and an oversized value can no longer be attached to an order.
* Fixed: the retired "force opt-in" setting's leftover database row is now removed when the plugin updates.

= 3.11.0 =
* New: every contact synced to Smaily now carries a field per store automation (welcome, first order, abandoned cart) recording when that automation last ran for them, so you can build Smaily segments that target — or exclude — the contacts your store automations have touched.
* New: an order line the customer has fully sent back (refunded in full, including a partial refund made from the order's Refund screen, which does not change the order status) is now reported to Smaily Campaign Intelligence as a return, so recommendations stop treating returned products as successful purchases. Returns are re-derived from the order on every sync, so existing orders pick this up on their next update.
* Fixed: the first-order automation now also fires on the WooCommerce Blocks / Store API checkout, not only on the classic checkout.
* Fixed: the Phone and Gender contact fields ticked in the setup wizard now actually reach Smaily.
* Fixed: on a store configured with the setup wizard, a registered customer's contact update (profile save, account details, checkout opt-in) reached Smaily with no email address and no fields, so nothing was updated.
* Fixed: the checkout and account opt-in forms now ask the customer for exactly the additional contact fields the merchant ticked, instead of ignoring the selection.
* Fixed: a store upgraded from an older plugin version keeps syncing the contact fields it has always synced; if its saved field selection cannot be read, the merchant is now told in an admin notice instead of contacts silently syncing with fewer fields.
* Fixed: turning the "Sync contacts to Smaily" switch off now actually stops the contact sync.
* Fixed: the welcome automation is now triggered only by a shopper creating their own account, not by accounts created through other paths (for example by an administrator in WordPress admin).
* Fixed: importing existing contacts to Smaily now imports every contact, not only the first hundred.
* Fixed: a contact import with nothing to sync now finishes on its own instead of appearing to stall.
* Fixed: abandoned-cart reminders now always include the shopper's name and the product details (name, price, image, link) the reminder template needs, also on stores that never opened the older abandoned-cart settings.
* Fixed: email-link attribution (which product recommendation a purchase came from) is now captured in the browser on a connected store even when browse tracking is off — previously that capture only ran as part of browse tracking, so a store with browse tracking off and a full-page cache had no way to record it.
* Fixed: a malformed recommendation id in a landing URL no longer travels onto the order. Smaily Campaign Intelligence rejects the whole order over an invalid id, so the order used to be lost rather than just its attribution.
* Improved: when Smaily refuses a request because your Smaily package does not include the feature, the Event Log and the connection test now say so, instead of reporting it as a Smaily outage that will recover on its own.
* Improved: the setup wizard's copy and terminology were reviewed end to end (English and Estonian) — the Smaily account link now sits on the first step, the Campaign Intelligence automations stay hidden until Campaign Intelligence is connected, and the Event Log advisory was dropped from the overview step.
* Changed: the "force opt-in" choice on automation triggers is retired. An automation enrols a contact Smaily has never seen, but never overrides an unsubscribe the contact made in Smaily — which is what every store that left the setting at its default already did.
* Hardened: a Smaily refusal that can never succeed (an invalid request, a package block) is no longer retried indefinitely; retries now respect Smaily's own wait hint and stop at a ceiling, and the reason is shown in the Event Log.
* Hardened: browse-tracking events no longer forward a browser-supplied recommendation id or campaign context to Smaily Campaign Intelligence. Real recommendation attribution is unaffected — it travels with the order, not with browse events.

= 3.10.0 =
* Improved: the Transactional emails settings are now discoverable — the account connection lives on the Connection tab (with its own connection test), and the Order-confirmation / Shipping-confirmation triggers now show on the WooCommerce tab once that account is connected, instead of being buried as a fourth section under WooCommerce automations. Wording also clarifies this is a separate Smaily account, not a "sub-account".
* Improved: the Event Log's "Retry all failed" control is now also shown when older failed events exist without any failures in the last 24 hours, so those can be retried without waiting for a fresh failure.

= 3.9.0 =
* New: Transactional emails (off by default) — Smaily Connect can now send order confirmation and shipping confirmation emails through a separate Smaily account dedicated to transactional sending, kept isolated from your marketing account's deliverability. When enabled, the matching native WooCommerce email is suppressed; if a Smaily send ever fails, the native WooCommerce email is sent instead so the customer is never left without a confirmation.
* Fixed: order confirmation emails (see above) now also fire correctly for the WooCommerce Blocks / Store API checkout, not just the classic checkout.
* Fixed: a product-removal update that previously failed to reach Smaily Campaign Intelligence with an error now repairs and resends automatically on retry, instead of failing with the same error indefinitely.
* Hardened: a transactional confirmation stuck retrying due to a prolonged connection problem now gives up after about an hour and falls back to the native WooCommerce email, instead of retrying indefinitely.
* Hardened: text used to personalize transactional emails (customer name, order number, payment/shipping method, product name/description) is now safely escaped before sending.

= 3.8.1 =
* Fixed: a product removed from Smaily Campaign Intelligence's recommendations (when its underlying WooCommerce data is already gone) is now reliably removed even in edge cases where it previously could be skipped.
* Fixed: products rescued under your store's default category (see 3.8.0) are now clearly marked internally so Smaily Campaign Intelligence categorizes their recommendations correctly rather than treating them as regular tagged products.
* Hardened: the browse-tracking endpoint no longer accepts a browser-supplied identity value; a shopper's identity is only ever established from their real, server-verified WordPress login session, as already applied since 3.8.0.

= 3.8.0 =
* New: browsing activity from a logged-in shopper is now linked to their account for their whole visit, not just right after they log in. This is resolved securely on the server from their WordPress login session — their email address is never sent to or exposed in the browser — and a shopper who has opted out of recommendation profiling is still respected (their activity stays anonymous, same as before).
* Fixed: published products with no category assigned are no longer silently excluded from Smaily Campaign Intelligence. They now sync under the store's own default product category (WooCommerce's normal "Uncategorized" behaviour), instead of being rejected for missing a required field.
* Fixed: opening the "Add new product" screen in WordPress no longer creates a doomed, empty sync entry for the not-yet-saved placeholder product. Only products you actually save are synced.

= 3.7.2 =
* Fixed: email-link attribution (which product recommendation a purchase came from) is no longer lost on storefronts that serve cached pages, or when a shopper decides the cookie-consent banner after landing. The attribution details are now captured from the link as soon as the page loads in the browser, independently of cookie consent and of whether the page was server-rendered or served from cache; browsing/tracking events themselves remain fully consent-gated as before.

= 3.7.1 =
* Fixed: browse tracking events for "add to cart" and "remove from cart" now identify products the same way as the rest of the catalog (a stable platform id), instead of the raw WooCommerce SKU field. The SKU field can be blank, reused across products, or not match catalog rows, which prevented these events from being matched to the right product in Smaily Campaign Intelligence.
* Fixed: hardened the browse-tracking endpoint so an oversized request is always rejected before any per-product lookup work is done, closing a potential resource-exhaustion path on that public endpoint.
* Fixed: removed a harmless no-op from the uninstall cleanup (it referenced a database table name as if it were a plugin setting).

= 3.7.0 =
* Changed: abandoned-cart reminders now run on the same unified pipeline as the rest of Smaily Connect (catalog, customers, orders), replacing the older, separate abandoned-cart code path. Your existing settings (on/off, cutoff, which fields to include) carry over automatically — no reconfiguration needed.
* Fixed: a disabled Smaily workflow could still appear as a selectable option in the Contact Form 7, classic Widget, Gutenberg block, and Elementor autoresponder dropdowns, so a form enrolled into it silently sent nothing. Disabled workflows are now filtered out of the dropdown; a form or widget already pointed at a workflow that has since been disabled keeps showing it (clearly flagged with a warning) instead of silently reverting to "No autoresponder".
* Improved: when Smaily can't be reached, the profiling opt-out check now falls back to a durable local opt-out record and the last known-good answer before ever defaulting to "allowed" — a known opt-out can no longer be re-allowed during a Smaily outage.
* Improved: the WordPress Privacy exporter/eraser (Tools > Export/Erase Personal Data) now also covers the abandoned-cart session tracker, and is listed under the friendlier name "Smaily Connect data".
* Improved: uninstalling the plugin now also removes the Smaily Campaign Intelligence connection settings and the profiling opt-out registry, leaving no leftover data behind.
* New: the Integrations page (setup wizard and Settings) now includes a "How to add a Smaily signup form" guide showing exactly where to place a Smaily sign-up form on your site — shortcode, Gutenberg block, Elementor widget, classic Widget, and Contact Form 7 — with a link to the full documentation.

= 3.6.1 =
* Fixed: order amounts sent to Smaily Campaign Intelligence are now gross (tax-inclusive), as the engine contract specifies. Previously line-item totals, unit prices and discount amounts were sent excluding tax while the order total included tax, which understated per-product revenue in Insights on stores that charge VAT/sales tax. **For stores already connected to Campaign Intelligence:** historical order rows already sent with tax-exclusive amounts are corrected by a one-time re-sync coordinated by the Smaily team on the engine side — nothing needs to be done on the store.

= 3.6.0 =
* Changed: products sent to Smaily Campaign Intelligence are now identified by a stable platform id (`woo-<id>`) instead of the merchant-entered SKU field. Merchant SKUs are optional, blank, or reused on many real stores, which could collapse distinct products into one and silently lose recommendation history; the platform id is unique and permanent. The merchant SKU field is no longer sent at all. **For stores already connected to Campaign Intelligence:** the product keys change on update, so the engine-side catalog needs a one-time coordinated cleanup and a full catalog + order re-import (re-backfill) — this is coordinated per store with the Smaily team; do not update a connected store without that coordination.
* New: permanently deleting a product (emptying it from the trash or deleting it outright) now tells the engine to remove that product and all its variants from the recommendation catalog. Trashing a product still keeps it in the catalog as out-of-stock (restorable), as before.
* New: product data sent to the engine now carries a product-level grouping id, so variants of the same product are recognized as one product for recommendation cadence and removal.
* New: a Documentation link in the setup wizard, the Settings screen, and the Plugins page, pointing to the new Smaily Connect user documentation site (in English and Estonian).

= 3.5.0 =
* Improved: the contact backfill progress now shows two honest numbers — how many WordPress users were checked, and how many contacts were actually synced to Smaily according to your contact sync mode (in consent mode only opted-in users are synced; that has always been the case, but the progress display previously showed the checked-users count labelled as synced contacts).
* New: before starting a backfill, the panel shows an estimate of how many contacts your current sync mode will actually sync (e.g. "about 16000 of them will be synced to Smaily as contacts").
* Fixed: the wizard's Done summary counted checked users as synced contacts.

= 3.4.3 =
* Fixed: saving the WooCommerce settings stored the abandoned-cart on/off state in a format the abandoned-cart email task could not read, which crashed the task on PHP 8 every 15 minutes — and turning the feature off did not stop it. The setting is now stored and read in one consistent format, and already-affected sites are healed automatically on update.
* Fixed: the wizard could show abandoned cart as enabled when it was actually disabled (on stores upgraded from an older plugin version).
* Improved: abandoned-cart reminders now use the workflow you map in the setup wizard / settings (including per-language workflows on multilingual stores). Stores upgraded from an older plugin version that have not run the wizard keep using their previously configured autoresponder, which is now also preserved when settings are re-saved.

= 3.4.2 =
* Fixed: a corrupted abandoned-cart row (left behind by an older plugin version after an in-place module swap) could crash the abandoned-cart email task on PHP 8 and repeat every 15 minutes, blocking all abandoned-cart reminders. Such rows are now skipped and logged, and one bad cart can no longer stop the others.
* Fixed: the plugin no longer re-creates the old WP-Cron schedules (for example after WooCommerce is re-activated) — scheduling is fully owned by the new task scheduler. Updating also cleans up leftover old schedules automatically.
* Fixed: the retired daily subscriber sync can no longer run even if an old scheduled event still exists on the site (it could overwrite contact languages with the wrong value).
* Fixed: abandoned-cart reminder emails now resolve the contact's language with the same logic as contact sync, instead of a method that could produce an empty or wrong language in scheduled runs.

= 3.4.1 =
* Fixes to the engine-run automations settings from real-store testing:
* Fixed: on a store whose language setup is store-wide, every automation row now shows the same uniform per-language workflow rows — a row no longer switches layout based on how it happened to be saved.
* Fixed: the cooldown input no longer snaps to 0 while you type; you can clear it and type a new value, and it is validated when you leave the field.
* New: a warning is shown when an automation is enabled in test mode but no test addresses are listed (the engine sends test emails only to the listed addresses, so an empty list means nobody receives anything).
* Improved: connection-failure messages are clearer — a human-readable summary pointing to the Campaign Intelligence tab, with the technical detail shown separately.
* Improved: on English-locale stores the automation recipe descriptions now show in English when the engine catalog provides them (recipe_en).
* New: **Engine-run recommendation automations** settings. When Smaily Campaign Intelligence is connected, the WooCommerce automations tab (and wizard Step 3) gains a section where you enable the engine's automation triggers (replenishment due, win-back, and more — the list comes from the engine for your store's sector) and bind each to your own Smaily workflow, with per-language workflow support on multilingual stores. Everything is fail-closed: triggers start off and in test mode (emails go only to your test addresses), and going live is a separate confirmed action. Sending happens engine-side; the plugin only saves your configuration.

= 3.3.2 =
* Browse tracking relies on the standard **WordPress Consent API** for consent. If browse tracking is on but no consent signal is present, the plugin now shows an admin notice explaining that the free **WP Consent API** plugin must be installed so your cookie banner (CookieYes, Complianz, Real Cookie Banner, …) can pass consent to Smaily — otherwise no browse data is collected. Reverts the CookieYes-specific consent reading added in 3.3.1 in favour of the standard, which CookieYes supports once the WP Consent API plugin is active.

= 3.3.1 =
* Fix: browse tracking on stores using CookieYes for cookie consent. (Superseded by 3.3.2, which uses the standard WordPress Consent API path instead of reading CookieYes directly.)

= 3.3.0 =
* Browse tracking now includes an anonymous Smaily visitor token (from a recommendation-link click) on browse events, so future personalization can use a shopper's browse history. Purchase attribution is unchanged — it is still credited from the order, not from browse. No recommendation id or email is attached to browse events. Only affects stores with browse tracking enabled and consented.

= 3.2.1 =
* Improved: the contact-sync mode selector now appears only when contact sync is enabled, and the "Checkout opt-in only" mode is selectable only when the checkout subscription checkbox is turned on. Restyled to match the rest of the setup wizard. No functional or data change.

= 3.2.0 =
* Fix: recommendation attribution is now captured on the WooCommerce **Block (Store API) checkout**, not just the classic checkout. On a block-checkout store the recommendation id was captured but never stamped onto the order; block-checkout orders now carry the attribution so purchases are credited to the recommendation.
* Contact sync modes: choose how customers are synced to Smaily by your lawful basis — "All customers (legitimate interest)", "Subscribers only (consent)" (default), or "Checkout opt-in only" — in the setup wizard / Settings. Consent mode mirrors Smaily unsubscribes/re-subscribes back into WordPress.
* Contacts are now synced with the correct language consistently (including from background jobs), and an opt-in / opt-out made in WordPress propagates to Smaily.
* Guest-checkout contacts and the checkout opt-in checkbox are now honoured by the contact sync.

= 3.1.0 =
* Recommendation attribution (landing capture): when a shopper clicks a product recommendation in a Smaily email and lands on the shop, the plugin now captures the recommendation id server-side and attaches it to the resulting order, so the engine can credit the purchase to the recommendation. Captured as a first-party functional cookie, independent of the browse-tracking consent toggle; disable with the `smaily_connect_capture_attribution` filter.

= 3.0.1 =
* Internationalization: the React admin UI (setup wizard + Settings) is now fully translatable, and the plugin ships a complete Estonian translation. No functional change.

= 3.0.0 =
First general-availability release, graduating the 2.1.0-beta line. Existing settings, credentials and connections are preserved; an in-place update needs no re-import.
* WooCommerce → Smaily Campaign Intelligence sync: catalog, customers, orders and consent-gated browse tracking, with backfill of existing data, an Event Log (with per-row retry and the request/response detail), and GDPR export / erase / opt-out.
* Hardening: WordPress.org Plugin Check pass (sanitization, escaping, prefixing, ABSPATH guards); editor blocks updated to Block API v3 for the WordPress 7.0 iframe editor; diagnostics gated behind WP_DEBUG.

== Upgrade Notice ==

= 3.11.2 =
This is the major version 3 rewrite. After updating, open Smaily Connect in the admin menu and confirm your settings in the setup wizard. Live contact syncing keeps working meanwhile; the daily catch-up sync resumes once the wizard is confirmed. See the migration guide for the full upgrade path.

= 3.11.1 =
Fixes only. The order-completion signal for Campaign Intelligence is now sent as soon as the order is confirmed, instead of depending on the shopper keeping the page open; email-link attribution values are validated before being stored; a retired setting leaves no row behind. Safe update.

= 3.11.0 =
Reliability release for contact sync and automations: ticked fields (Phone, Gender) reach Smaily and are asked at checkout, import no longer stops at 100, sync-off really stops it, first-order fires on block checkout. Adds per-automation last-run fields, returns; force opt-in retired. Safe update.

= 3.10.0 =
Transactional emails settings moved to the Connection and WooCommerce tabs for discoverability; Event Log "Retry all failed" now also reachable for older failed events. Safe update.

= 3.9.0 =
Adds an optional Transactional emails feature (off by default) sending order/shipping confirmations via a dedicated Smaily account, with automatic fallback to native WooCommerce emails on failure. Also includes reliability and security hardening fixes. Safe update.

= 3.8.1 =
Reliability + hardening only, no new features. Deleted-product removal from Campaign Intelligence is more reliable; default-category products are now correctly categorized; the browse-tracking endpoint no longer accepts a browser-supplied identity value. Safe update.

= 3.8.0 =
Logged-in shoppers' browsing is now linked to their account for the session (resolved server-side, email never exposed to the browser, opt-outs respected). Products with no category now sync under your store's default category. Empty product drafts no longer create sync noise. Safe update.

= 3.7.2 =
Fixes email-link attribution being lost on cached storefronts or when consent is decided after landing. Safe update.

= 3.7.1 =
Browse "add to cart"/"remove from cart" tracking events now key products correctly (previously could use an unreliable SKU field); hardened the browse-tracking endpoint against oversized requests. Safe update.

= 3.7.0 =
Abandoned-cart reminders now run on the unified pipeline (settings carry over automatically); disabled Smaily workflows can no longer be silently selected in form/widget dropdowns; GDPR/privacy handling hardened. Safe update.

= 3.6.1 =
Order amounts sent to Smaily Campaign Intelligence are now gross (tax-inclusive), fixing understated per-product revenue on taxed stores. Historical rows on already-connected stores are corrected by a Smaily-coordinated engine-side re-sync — no action needed on the store. Safe update.

= 3.6.0 =
Product identity sent to Smaily Campaign Intelligence changes from the merchant SKU to a stable platform id. Already-connected stores need a coordinated engine-side catalog cleanup and full re-import — coordinate with Smaily before updating. Stores without Campaign Intelligence: safe update.

= 3.5.0 =
The contact backfill progress now reports users checked and contacts synced as separate numbers, matching your contact sync mode — no data-flow change, only honest reporting. Safe in-place update.

= 3.4.3 =
Critical fix: saving WooCommerce settings could crash the abandoned-cart email task on PHP 8 every 15 minutes (and turning the feature off did not stop it). Update immediately if you use abandoned-cart reminders; affected sites are healed automatically.

= 3.4.2 =
Reliability fixes for stores upgraded from an older plugin version: abandoned-cart crash loop fixed, leftover legacy schedules cleaned up on update, abandoned-cart contact-language handling corrected. Recommended for all stores.

= 3.4.1 =
Settings-screen fixes for the engine-run automations section (language rows, cooldown input, test-address warning, clearer error messages, English recipes). UI-only; safe in-place update.

= 3.4.0 =
Adds the engine-run recommendation automations settings section (visible when Smaily Campaign Intelligence is connected). Fail-closed: nothing is enabled until you configure it; safe in-place update.

= 3.3.2 =
Browse tracking uses the standard WordPress Consent API; if no consent signal is present you'll now be told to install the free WP Consent API plugin. Recommended for any store using browse tracking with a cookie banner.

= 3.3.1 =
Superseded by 3.3.2.

= 3.3.0 =
Browse events now carry an anonymous visitor token for future personalization. No change to purchase attribution or store behavior; safe in-place update.

= 3.2.1 =
Minor UI refinement to the contact-sync mode selector. No functional or data change; safe in-place update.

= 3.2.0 =
Fixes recommendation attribution on the WooCommerce Block checkout and adds contact-sync modes. Recommended especially for stores using the block checkout.

= 3.1.0 =
Recommendation attribution: rec-link clicks are now captured server-side and attached to orders, so the engine can credit purchases to email recommendations. No re-import required.

= 3.0.1 =
Translation update: the admin interface is now fully translatable and ships a complete Estonian translation. No functional change; no re-import required.

== Screenshots ==

1. Smaily Connect Admin View
2. Getting Started
3. Subscriber Synchronization
4. Abandoned Cart Reminder Emails
5. Import Products To Templates From RSS-Feed
6. Opt-In Form Block
7. Integrate With Contact Form 7
8. Smaily Elementor Opt-In Form
