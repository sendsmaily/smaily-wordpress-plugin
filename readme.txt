=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 8.0
Requires at least: 6.6
Tested up to: 7.1
WC requires at least: 6.9
WC tested up to: 10.7
Stable tag: 3.25.0
License: GPLv3 or later

Connect WordPress and WooCommerce to Smaily to collect subscribers, automate emails and add optional personalized product recommendations.

== Description ==

= Smaily email marketing for WordPress and WooCommerce =

Connect your WordPress site or WooCommerce store to your Smaily account. Collect subscribers, synchronize contacts, trigger automated emails and bring store products into your campaigns from one plugin.

Smaily Connect works with WordPress, WooCommerce, Contact Form 7 and Elementor. A Smaily account with API access is required.

= Collect subscribers where they convert =

Add Smaily signup forms wherever they fit your site:

* Smaily Sign-Up Form block and classic widget
* Smaily Landing Page block
* Shortcode for flexible placement
* Contact Form 7 integration
* Elementor widget
* WooCommerce registration and checkout opt-in

New subscribers are sent to your Smaily account, where you can manage contacts and continue the communication.

= Keep contacts synchronized =

Choose who is sent to Smaily and which customer fields are included. Consent-based synchronization is the default, and WooCommerce stores can also include guest checkout contacts where configured.

Smaily Connect resolves each contact's language through WPML, Polylang or TranslatePress so contacts can reach the correct Smaily list and workflow in multilingual setups.

= Automate key WooCommerce moments =

Map WooCommerce events to workflows you have created in Smaily:

* Welcome a shopper who subscribes to your marketing emails in the store
* Respond to a customer's first order
* Remind a shopper about an abandoned cart after your chosen delay. In the "Subscribers only (consent)" and "Checkout opt-in only" contact-sync modes, only a shopper who has subscribed gets a reminder; a shopper who unsubscribed in Smaily never gets one

Each of these emails can carry a single-use discount code that the plugin copies from a WooCommerce coupon you pick. A code works once, only for the shopper's email address, for the number of days you choose.

You can also use the product RSS feed to bring WooCommerce products directly into Smaily email templates. With Campaign Intelligence connected, the Edit design button on an email card opens that email's design page.

= Optional personalized product recommendations =

Connect WooCommerce to Smaily Campaign Intelligence to add shopper-specific product recommendations and purchase-based automations to your email marketing.

Once connected, products, customers and orders synchronize automatically. Connecting also imports your existing products, customers and orders in the background, one after another, so Campaign Intelligence has the history it needs to begin producing relevant recommendations. Optional browse tracking can add consent-based browsing signals.

Campaign Intelligence is an optional paid Smaily add-on. It is not enabled by default and is billed separately by Smaily.

= Optional transactional emails =

Connect a separate Smaily Transactional account to send WooCommerce order and shipping confirmations through Smaily, a follow-up email a set number of days after an order reaches a status you choose, and a payment reminder to a shopper who placed an order and left it unpaid at the payment step. If an order or shipping confirmation fails in Smaily, the native WooCommerce email is used as a fallback. The follow-up email and the payment reminder have no WooCommerce fallback: a failed one shows in the Event Log, where you can retry it.

= See what is happening =

The guided setup wizard takes you through the connection, contact synchronization, automations and optional services. After setup, manage your connections and enabled features under Smaily Connect Settings.

The Event Log lets you inspect synchronization events, understand failures and retry failed items from the WordPress admin.

= Privacy and control =

* Choose the contact synchronization mode that matches your lawful basis.
* Browse tracking is off by default and only sends events when it is enabled by the site administrator and the shopper has given the required consent.
* Shoppers can opt out of recommendation profiling from their WooCommerce account.
* Smaily Connect supports the WordPress personal-data export and erasure tools.
* The discount codes of automation emails are WooCommerce coupons that hold the shopper's email address. The personal-data export and erasure tools cover them, and an unused code is deleted 30 days after it expires.

= Documentation and support =

Read the [Smaily Connect documentation](https://smaily.com/connect-woo/) for setup instructions, feature details and troubleshooting.

For support, visit the [Smaily Help Center](https://smaily.com/help/) or contact Smaily.

= External services =

Smaily Connect communicates with services operated by Sendsmaily OÜ. These connections are required only for the features that the site administrator configures.

**Smaily Public API**

The plugin uses the [Smaily Public API](https://smaily.com/help/api/) to connect to your Smaily account. Depending on the enabled features, it is used to:

* validate Smaily account credentials;
* list available Smaily automation workflows;
* synchronize contacts and subscription status;
* trigger workflows after form submissions and WooCommerce events, with the shopper's discount code and its last valid day when a discount code is switched on for the automation;
* check a shopper's subscription status before an abandoned-cart reminder;
* send abandoned-cart data to the selected Smaily workflow; and
* send order and shipping confirmations, the follow-up email and the payment reminder, with the order's payment page address, when Smaily Transactional is configured.

**Smaily installation report**

When a Smaily account is connected, Smaily Connect sends Smaily (https://subscription.smaily.com) an installation report when you save the settings, once a day, and when you remove a connection or deactivate the plugin, authenticated with the connected Smaily account's API credentials, which Smaily only checks and never stores: the site address, the WordPress, WooCommerce, PHP and plugin versions, the connected Smaily accounts, the switched-on features, whether each connection works (error codes only) and the time of the last successful sync — never customer, subscriber or order data.

**Smaily Campaign Intelligence — optional**

Campaign Intelligence (https://intelligence.smaily.com) is connected by the site administrator, either with a one-time setup link issued by Smaily or by starting it from the plugin with "Try Intelligence free". Before a connection exists, the plugin contacts the service only in these two cases:

* when the site administrator opens Settings or setup step 4 while a Smaily account is connected and Campaign Intelligence is not, a request that asks whether the store can start Campaign Intelligence from the plugin (at most once per 5 minutes per site). It carries no personal data: the service sees only the site's IP address and the plugin's User-Agent; and
* when the site administrator presses "Try Intelligence free", the shop address, the main Smaily account's subdomain, API username and password, the signed-in administrator's email address and the administrator's language, to start Campaign Intelligence for the store.

Once Campaign Intelligence is connected, the plugin sends the following WooCommerce data to that service:

* product catalog data, including titles, prices, categories, stock status and product URLs;
* once a night, the store's complete list of product identifiers and the stock status of each, so Campaign Intelligence can remove products the store no longer offers;
* customer records, including email address, name and registration date, only for the customers Campaign Intelligence can use: the customers your contact sync sends to Smaily, the registered customers with an order inside the engine's history window (60 months by default), and a customer who logs in after giving marketing consent;
* order data, including order status, totals and purchased items;
* when a customer logs in with the store's visitor cookie or a browsing session ID in the browser, from a background job after the login: the customer's email address and store user ID with those values, so Campaign Intelligence can link the customer's earlier visits. A customer who opted out of recommendation profiling is left out. For a customer who has given marketing consent and has no visitor cookie yet, the store sets one at login with a random value (365 days by default). The cookie stays after logout, and each login with marketing consent renews its lifetime;
* while a history import runs, its progress: the phase, dates and the number of records sent, with no customer details;
* when the site administrator presses "Open Campaign Intelligence" in Settings, the signed-in administrator's email address, to get a one-time sign-in link to the store's Campaign Intelligence console;
* browsing events, including product views, searches, cart events and checkout events, only when browse tracking is enabled and the shopper has given the required consent;
* requests for a shopper's product recommendations, when a page with the Smaily Recommendations block or shortcode loads and the shopper has given marketing consent. The shopper's browser asks your store, and your store asks Campaign Intelligence, sending the logged-in customer's store user ID or the store's visitor cookie value;
* when the site administrator uses Edit design, requests for the list of email designs and for a one-time link to a design page, with the address of the admin page to return to;
* when the site administrator disconnects Campaign Intelligence or deletes the plugin, a request that ends the connection: Campaign Intelligence revokes the plugin's key and, if the store has no other connection, pauses the store and keeps its data; and
* personal-data export, erasure and profiling opt-out requests so the corresponding WordPress and shopper controls are honored by Campaign Intelligence.

The site administrator controls the enabled features in the plugin settings.

Privacy Policy: [Smaily Privacy Policy](https://smaily.com/privacy-policy/)

Terms of Service: [Smaily Terms of Service](https://smaily.com/terms-of-service/)

= Contribute =

Contribute to the development through [GitHub](https://github.com/sendsmaily/smaily-wordpress-plugin). We welcome new issues and pull requests.

== Installation ==

1. Install Smaily Connect through Plugins → Add New Plugin in WordPress, or upload the plugin ZIP.
2. Activate the plugin and open Smaily Connect from the WordPress admin menu.
3. Follow the setup wizard and connect your Smaily account using the API credentials created under Settings → API in Smaily.
4. Choose who is synchronized, select any additional customer fields and import existing contacts if needed.
5. Map the WooCommerce events you want to use to existing Smaily automation workflows.
6. Configure signup forms, checkout opt-in and the product RSS feed as needed.
7. If you use Campaign Intelligence or Smaily Transactional, connect those optional services in the relevant setup step or later under Settings.

== Frequently Asked Questions ==

= Do I need a Smaily account? =

Yes. Smaily Connect sends contacts and triggers to your own Smaily account. Your Smaily package must include API access. Create the required API user under Settings → API in Smaily.

= Can I use Smaily Connect without WooCommerce? =

Yes. On a WordPress site, you can collect subscribers using the Smaily blocks, classic widget, shortcode, Contact Form 7 integration or Elementor widget. WooCommerce is required only for store-specific features such as checkout opt-in, customer and order events, abandoned-cart emails, product feeds, transactional emails and Campaign Intelligence.

= Which WooCommerce automations can I connect? =

You can map welcome, first-order and abandoned-cart events to automation workflows created in Smaily. An abandoned-cart reminder follows the consent rule of your contact-sync mode and is never sent to a shopper who unsubscribed in Smaily. When Campaign Intelligence is connected, additional purchase-based automation options may also be available for your store.

= What is Smaily Campaign Intelligence? =

Campaign Intelligence is Smaily's optional recommendation add-on. It uses WooCommerce product, customer and order data to produce personalized product recommendations and purchase-based automation signals. Consent-based browsing data can also be included when browse tracking is enabled.

Campaign Intelligence is a paid Smaily add-on, billed separately by Smaily. It is not required to use the other Smaily Connect features.

= Is browsing activity collected automatically? =

No. Browse tracking is off by default. Events are sent only when the site administrator enables the feature and the shopper has given the required consent through the WordPress Consent API. Shoppers who opt out of recommendation profiling are excluded.

= Can Smaily Connect send order and shipping confirmations? =

Yes. You can optionally connect a separate Smaily Transactional account and use it for WooCommerce order and shipping confirmations, for a follow-up email a set number of days after an order reaches a status you choose, and for a payment reminder to a shopper who left an order unpaid at the payment step. If an order or shipping confirmation fails in Smaily, the plugin falls back to the corresponding native WooCommerce email. The follow-up email and the payment reminder have no WooCommerce fallback: a failed one shows in the Event Log, where you can retry it.

= Does Smaily Connect support multilingual stores? =

Yes. Smaily Connect resolves contact language through WPML, Polylang and TranslatePress and can route contacts and workflows by language.

= Is it safe to import existing data again? =

Yes. Imports run in the background and can be safely repeated. Synchronization events use stable identifiers so repeating an import does not create duplicate records.

= What happens when I upgrade from an older version? =

Existing settings, credentials and connections are preserved. If the setup wizard has already been completed, the v3 synchronization process becomes active after the upgrade. If setup was never completed, the existing live contact synchronization continues until the wizard is finished.

= What happens when I uninstall the plugin? =

Uninstalling removes the plugin settings and local queue data from the WordPress site. If Campaign Intelligence is connected, the plugin first tells it that the connection ends; unless the store has another connection, Campaign Intelligence pauses the store. Contacts already stored in Smaily and data stored in Campaign Intelligence are not automatically deleted. See the Smaily Connect documentation and Smaily Privacy Policy for deletion options.

= Where can I get help? =

Use the [Smaily Connect documentation](https://smaily.com/connect-woo/) for setup and troubleshooting. You can also visit the [Smaily Help Center](https://smaily.com/help/).

== Changelog ==

The latest releases are listed here. Earlier releases are listed at https://github.com/sendsmaily/smaily-wordpress-plugin/releases

= 3.25.0 =
* New: Try Intelligence free. A store with a connected Smaily account can start Campaign Intelligence from the plugin, without asking Smaily for a setup link. The button appears when Smaily opens the self-service start; until then the banner offers Contact Smaily.
* New: a Campaign Intelligence banner on the Campaign Intelligence tab, the setup wizard's step 4 and on top of Settings, where it can be dismissed for 30 days. When the Smaily package has no API access, it says so and links to your Smaily account.
* New: a store with no connected Smaily account sees a Smaily invitation banner on the Dashboard and on the setup wizard's first step. Dismissing it hides it, and the one-line notice on other screens, for 90 days.
* New: while Campaign Intelligence is connected, its tab tells you how to show recommended products in the store: the Smaily Recommendations block or the `[smaily_recommendations]` shortcode.
* Fixed: product descriptions in the product RSS feed show their formatting in the email instead of HTML code, and product titles show quotes and ampersands as characters.
* Fixed: profile fields that a developer adds to the My Account registration form are saved for the new customer.
* Fixed: a newsletter form on a page whose address holds spaces or special characters, such as Estonian letters, returns the subscriber to that page.
* Fixed: the RSS feed Limit field in the Integrations step never shows more than 100, the most the feed lists.
* Fixed: the debug log and a stored error no longer include the database's own error text.
* Changed: the admin screens use the React that WordPress already loads, so the plugin's admin download is smaller.
* Changed: the product RSS feed's dates use the standard RSS format with a four-digit year and a time zone.
* Updated to the Smaily Campaign Intelligence API contract v1.34.0.

= 3.24.1 =
* Security fix: the My Account → Account details form and the user profile in wp-admin show only the profile values of the user being edited. This fixes a reported information-disclosure issue, where a logged-in user could see another user's values.
* Security fix: the Elementor Pro form action's "Other fields" list shows field names as plain text.
* Security fix: the product RSS feed never lists password-protected products.
* Changed: the product RSS feed lists at most 100 products. A feed address that asks for more lists 100. The Limit field in the Integrations step now goes from 1 to 100 (it went to 250).
* Security fix: the newsletter block's button accepts only real colours, widths and corner sizes.
* Security fix: an admin notice can be dismissed only by a user who can see it.
* Security fix: the newsletter forms' default return address always uses the store's own address.

= 3.24.0 =
* New: several shops can share one Campaign Intelligence store. Each shop's connection puts its own code prefix in front of the product and order codes it sends, so shops that number them alike do not overwrite each other. A shop that joins a store with several shops must run 3.24.0 before its setup link is used. Existing stores send what they sent before.
* New: the payment reminder, a transactional email to a shopper who placed an order at the checkout and left it unpaid at the payment step, with a link to pay, after the delay you set.
* New: a store with no connected Smaily account sees a notice that invites it to connect one or to sign up. It can be dismissed for 90 days.
* Improved: a newsletter box ticked on the block checkout counts for the abandoned-cart reminder at once, before the order is placed.
* Fixed: on the classic checkout, the abandoned-cart reminder goes to the email address the shopper typed or corrected last.
* Fixed: an abandoned-cart cutoff time over 1425 minutes, which could never send a reminder, is refused when you save it.
* Fixed: an order with no billing email is skipped instead of failing in Campaign Intelligence on every retry.
* Fixed: old queue records are cleaned up in steps until none are left, and records from a history import are kept 7 days instead of 30. A queue table left oversized by the 3.21.0 import loop empties soon after the update.
* Privacy: the personal-data export lists the shopper's bookings and visits held in Campaign Intelligence.
* Updated to the Smaily Campaign Intelligence API contract v1.33.0.

= 3.23.0 =
* Fixed: one product that cannot be loaded no longer stops the products import. That product fails alone, and the rest are sent.
* Fixed: the nightly product list lists a product it cannot load as out of stock and sends the rest. When more than 20% of the products cannot be loaded, that night's list is not sent, and the Event Log says why.
* Fixed: a failing background database update no longer stops other plugins' background jobs. The import cards say so when the update is incomplete.
* Fixed: on a TranslatePress store, the per-language workflows are found, and abandoned-cart reminders go out in the shopper's storefront language.
* Improved: an import card shows an error when the import's state cannot be read from the database.
* Improved: the contact import lists the customers whose stored data cannot be read apart from the customers Smaily refused.
* Fixed: Edit design works in a browser that blocks third-party cookies, such as Safari. The window asks before it closes over unsaved changes.
* Updated to the Smaily Campaign Intelligence API contract v1.31.0.

= 3.22.0 =
* New: on a WPML or Polylang store, an abandoned-cart reminder goes out in the storefront language of the shopper's last cart change, guests included.
* Changed: the plugin adds only the newsletter box to the checkout and My Account forms. Phone, gender and birthday stay on the user profile in wp-admin.
* New: while a Smaily account is connected, the plugin sends Smaily an installation report: versions, accounts, switched-on features and whether each connection works. No customer or order data.
* New: Settings shows the installed plugin version.
* New: on an https admin, Edit design opens the email design page in a window over the admin.
* Improved: when a history import stops, Campaign Intelligence is told how far it got and why.
* Improved: Import now never starts a second run of an import that is already running.
* Improved: a product, customer or order that cannot be prepared for Campaign Intelligence fails alone, and the rest are sent. The contact import skips such a contact.
* Fixed: a setup link for another Smaily account's Campaign Intelligence store is refused.
* Fixed: a contact whose stored gender is Female now syncs to Smaily as Female (it was sent as Male).
* Privacy: logs, stored errors and the admin keep only error codes, never the message text from Smaily or Campaign Intelligence, which can name a customer.
* Privacy: the personal-data export and erasure also find the orders of the customer's account placed under an older address.
* Developer: new action hooks `smaily_connect_smaily_api_result` and `smaily_connect_engine_api_result` after each Smaily and Campaign Intelligence answer.
* Updated to the Smaily Campaign Intelligence API contract v1.29.0.

== Upgrade Notice ==

= 3.25.0 =
Adds Try Intelligence free and new Smaily and Campaign Intelligence banners, and the product RSS feed shows description formatting instead of HTML code.

= 3.24.1 =
Security release: fixes a reported issue where a logged-in user could see another user's profile values, and hardens the product RSS feed and the newsletter forms. Update now.

= 3.24.0 =
Several shops can share one Campaign Intelligence store: a shop that joins a store with several shops must run 3.24.0 before its setup link is used. Adds a payment reminder for orders left unpaid at the payment step. Existing connections send what they sent before.

= 3.23.0 =
One product that cannot be loaded no longer stops the products import or the nightly product list. Edit design works where the browser blocks third-party cookies, such as Safari. TranslatePress stores send abandoned-cart reminders in the shopper's language.

= 3.22.0 =
Abandoned-cart reminders follow the shopper's storefront language on WPML and Polylang stores. The checkout and My Account forms get only the newsletter box. A connected Smaily account gets an installation report with no customer data.

== Screenshots ==

1. Contact synchronisation — choose which contacts and customer fields are sent to Smaily.
2. Signup forms — collect subscribers through shortcode, Gutenberg, Elementor, the classic widget or Contact Form 7.
3. WooCommerce automations — connect welcome, first-order and abandoned-cart events to Smaily workflows.
4. Transactional emails — send order and shipping confirmations and a follow-up email through a separate Smaily Transactional account.
5. Product RSS feed — configure which WooCommerce products are loaded into Smaily email templates.
6. Campaign Intelligence connection — manage the engine connection and import existing products, customers and orders.
