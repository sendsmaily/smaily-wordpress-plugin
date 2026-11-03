=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 8.0
Requires at least: 6.6
Tested up to: 7.1
WC requires at least: 6.9
WC tested up to: 10.7
Stable tag: 3.21.0
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

Connect a separate Smaily Transactional account to send WooCommerce order and shipping confirmations through Smaily, and a follow-up email a set number of days after an order reaches a status you choose. If an order or shipping confirmation fails in Smaily, the native WooCommerce email is used as a fallback. The follow-up email has no WooCommerce fallback: a failed follow-up shows in the Event Log, where you can retry it.

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
* send order and shipping confirmations and the follow-up email when Smaily Transactional is configured.

**Smaily Campaign Intelligence — optional**

If the site administrator connects Campaign Intelligence using a one-time setup link issued by Smaily, the plugin sends the following WooCommerce data to that service:

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

Yes. You can optionally connect a separate Smaily Transactional account and use it for WooCommerce order and shipping confirmations, and for a follow-up email a set number of days after an order reaches a status you choose. If an order or shipping confirmation fails in Smaily, the plugin falls back to the corresponding native WooCommerce email. The follow-up email has no WooCommerce fallback: a failed follow-up shows in the Event Log, where you can retry it.

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

= 3.21.0 =
* New: connecting Campaign Intelligence imports your products, then customers, then orders, each when the one before has finished. Hold back or Cancel stops the sequence.
* New: a connected store that never finished its customers or orders import starts it once after this update, without a click. An import you held back or cancelled stays stopped.
* New: "Open Campaign Intelligence" in Settings opens your store's console with a one-time sign-in link.
* Improved: imports send faster, at a steady pace. The Orders import sends the last 18 months first, then older orders back to 60 months; older orders are not sent.
* Improved: the import cards count the records Campaign Intelligence accepted, and the import reports its progress to it.
* Improved: on legacy order storage, a database index added in the background speeds up the Orders import, only where the server can build it without blocking writes.
* Changed: Campaign Intelligence gets only the customers it can use: those your contact sync sends to Smaily, those with an order in the history window, and a customer who logs in with marketing consent.
* New: a customer who logs in with marketing consent gets the store's visitor cookie, so the Recommendations block knows them after logout. A login no longer waits for Campaign Intelligence.
* Improved: your store's server sets the email-click cookies, so Safari keeps them for their full lifetime.
* Changed: an automation card shows its workflow and settings only while it is on, and an automation that is off makes no discount code.
* Changed: a design Campaign Intelligence delivers to Smaily as a workflow is used for emails on the Smaily account it names, transactional emails included, in your store's default language. This replaces the 3.19.0 "main Smaily account" rule.
* Privacy: erasing a customer's personal data also cancels their waiting background login sync, so it cannot send the erased customer to Campaign Intelligence again.
* Fixed: an update from 1.x or 2.0.0 removes the old abandoned-cart table only after every unsent cart is copied.
* Fixed: the Edit design "Back to your store" link is set only for your store's own admin address.
* Updated to the Smaily Campaign Intelligence API contract v1.24.0.

= 3.20.0 =
* New: the welcome, first-order and abandoned-cart emails can each carry a single-use discount code. Pick a WooCommerce coupon and the number of days a code stays valid. Each code copies the coupon's discount and restrictions, brands included, works once and only for the shopper's email address, and reaches Smaily with its last valid day.
* New: a shopper who still has an unused, valid code from the same automation gets that code again, not a new one. No code is made for an address that WooCommerce cannot restrict a coupon to exactly; the email goes without it.
* New: unused codes are deleted 30 days after they expire.
* Privacy: the personal-data export lists the codes made for an address. Erasure deletes the unused ones and removes the address from the used ones.
* Changed: Disconnect and deleting the plugin now tell Campaign Intelligence that the connection ends. Campaign Intelligence revokes the plugin's key and, if your store has no other connection, pauses the store and keeps its data: no recommendations and none of its automated emails until you connect again with a new setup link.
* Updated to the Smaily Campaign Intelligence API contract v1.18.0.

= 3.19.0 =
* Changed: an abandoned-cart reminder follows your contact-sync mode. In "Subscribers only (consent)" and "Checkout opt-in only" it goes only to a shopper who subscribed in your store, ticked the newsletter box in the classic checkout, or is subscribed in Smaily. A shopper who unsubscribed in Smaily gets no reminder in any mode. A stopped reminder shows as skipped in the Event Log, with the reason.
* Improved: a tick in the classic checkout's newsletter box counts at once, also when the shopper leaves right after it.
* New: with Campaign Intelligence connected, every email card has an "Edit design" button that opens the email's design page.
* Changed: a design Campaign Intelligence delivers to Smaily as a workflow is used only for emails mapped on your main Smaily account and in your store's default language. Other emails keep the workflow you picked.
* Changed: the update copies the unsent carts from the abandoned-cart table of versions 1.x and 2.0.0, then removes that table and an unused one. This cannot be undone.
* Improved: the Event Log's Sent filter leaves out a reminder withdrawn because the shopper bought, and Details shows the row's status.
* Improved: the "Last sent" line on the transactional email cards reads less data.
* Fixed: clearing the workflow of a Campaign Intelligence automation saves again.

== Upgrade Notice ==

= 3.21.0 =
A store connected to Campaign Intelligence starts the imports it never finished (products, customers, orders) once after this update, unless you held them back. On legacy order storage it adds one posts-table index in the background, only where it builds online.

= 3.20.0 =
Adds single-use discount codes to the welcome, first-order and abandoned-cart emails. Disconnect and deleting the plugin now tell Campaign Intelligence that the connection ends; it pauses a store with no other connection and keeps its data. Safe update.

= 3.19.0 =
Abandoned-cart reminders now follow your contact-sync mode's consent rule and skip shoppers who unsubscribed in Smaily. Adds Edit design. Removes the 1.x/2.0.0 abandoned-cart table after copying its unsent carts; this cannot be undone.

== Screenshots ==

1. Contact synchronisation — choose which contacts and customer fields are sent to Smaily.
2. Signup forms — collect subscribers through shortcode, Gutenberg, Elementor, the classic widget or Contact Form 7.
3. WooCommerce automations — connect welcome, first-order and abandoned-cart events to Smaily workflows.
4. Transactional emails — send order and shipping confirmations and a follow-up email through a separate Smaily Transactional account.
5. Product RSS feed — configure which WooCommerce products are loaded into Smaily email templates.
6. Campaign Intelligence connection — manage the engine connection and import existing products, customers and orders.
