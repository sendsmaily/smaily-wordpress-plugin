=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 8.0
Requires at least: 6.6
Tested up to: 7.1
WC requires at least: 6.9
WC tested up to: 10.7
Stable tag: 3.16.1
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

* Welcome a customer after account registration
* Respond to a customer's first order
* Remind a shopper about an abandoned cart after your chosen delay

You can also use the product RSS feed to bring WooCommerce products directly into Smaily email templates.

= Optional personalized product recommendations =

Connect WooCommerce to Smaily Campaign Intelligence to add shopper-specific product recommendations and purchase-based automations to your email marketing.

Once connected, products, customers and orders synchronize automatically. You can also import existing data so Campaign Intelligence has the history it needs to begin producing relevant recommendations. Optional browse tracking can add consent-based browsing signals.

Campaign Intelligence is an optional paid Smaily add-on. It is not enabled by default and is billed separately by Smaily.

= Optional transactional emails =

Connect a separate Smaily Transactional account to send WooCommerce order and shipping confirmations through Smaily. If a Smaily send fails, the native WooCommerce email is used as a fallback.

= See what is happening =

The guided setup wizard takes you through the connection, contact synchronization, automations and optional services. After setup, manage your connections and enabled features under Smaily Connect Settings.

The Event Log lets you inspect synchronization events, understand failures and retry failed items from the WordPress admin.

= Privacy and control =

* Choose the contact synchronization mode that matches your lawful basis.
* Browse tracking is off by default and only sends events when it is enabled by the site administrator and the shopper has given the required consent.
* Shoppers can opt out of recommendation profiling from their WooCommerce account.
* Smaily Connect supports the WordPress personal-data export and erasure tools.

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
* trigger workflows after form submissions and WooCommerce events;
* send abandoned-cart data to the selected Smaily workflow; and
* send order and shipping confirmations when Smaily Transactional is configured.

**Smaily Campaign Intelligence — optional**

If the site administrator connects Campaign Intelligence using a one-time setup link issued by Smaily, the plugin sends the following WooCommerce data to that service:

* product catalog data, including titles, prices, categories, stock status and product URLs;
* customer records, including email address, name and registration date;
* order data, including order status, totals and purchased items;
* browsing events, including product views, searches, cart events and checkout events, only when browse tracking is enabled and the shopper has given the required consent; and
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

You can map welcome, first-order and abandoned-cart events to automation workflows created in Smaily. When Campaign Intelligence is connected, additional purchase-based automation options may also be available for your store.

= What is Smaily Campaign Intelligence? =

Campaign Intelligence is Smaily's optional recommendation add-on. It uses WooCommerce product, customer and order data to produce personalized product recommendations and purchase-based automation signals. Consent-based browsing data can also be included when browse tracking is enabled.

Campaign Intelligence is a paid Smaily add-on, billed separately by Smaily. It is not required to use the other Smaily Connect features.

= Is browsing activity collected automatically? =

No. Browse tracking is off by default. Events are sent only when the site administrator enables the feature and the shopper has given the required consent through the WordPress Consent API. Shoppers who opt out of recommendation profiling are excluded.

= Can Smaily Connect send order and shipping confirmations? =

Yes. You can optionally connect a separate Smaily Transactional account and use it for WooCommerce order and shipping confirmations. If a Smaily send fails, the plugin falls back to the corresponding native WooCommerce email.

= Does Smaily Connect support multilingual stores? =

Yes. Smaily Connect resolves contact language through WPML, Polylang and TranslatePress and can route contacts and workflows by language.

= Is it safe to import existing data again? =

Yes. Imports run in the background and can be safely repeated. Synchronization events use stable identifiers so repeating an import does not create duplicate records.

= What happens when I upgrade from an older version? =

Existing settings, credentials and connections are preserved. If the setup wizard has already been completed, the v3 synchronization process becomes active after the upgrade. If setup was never completed, the existing live contact synchronization continues until the wizard is finished.

= What happens when I uninstall the plugin? =

Uninstalling removes the plugin settings and local queue data from the WordPress site. Contacts already stored in Smaily and data stored in Campaign Intelligence are not automatically deleted. See the Smaily Connect documentation and Smaily Privacy Policy for deletion options.

= Where can I get help? =

Use the [Smaily Connect documentation](https://smaily.com/connect-woo/) for setup and troubleshooting. You can also visit the [Smaily Help Center](https://smaily.com/help/).

== Changelog ==

The latest releases are listed here. Earlier releases are listed at https://github.com/sendsmaily/smaily-wordpress-plugin/releases

= 3.16.1 =
* Fixed: in the Elementor Pro form editor, "Add Item" under "Other fields" in the Smaily action now adds a row, and the Smaily section no longer goes blank.
* Fixed: when Smaily refuses a request with a response code other than 203, the Event Log row now fails at once with Smaily's message instead of showing as sent. Code 225, a temporary Smaily database error, is retried.
* Fixed: Cancel or Hold back on an import now holds even when the import's next batch had already started; the import no longer switches back to running.
* Changed: once the initial setup is finished, the "Smaily Connect" admin menu opens Settings. The setup wizard stays available under "Run setup again".

= 3.16.0 =
* New: a "Smaily Recommendations" block and the `[smaily_recommendations]` shortcode show a shopper's personal product recommendations in your store, for logged-in customers and for returning guests. The cards load after the page, so the page stays fast and can stay in your page cache, and they appear only for shoppers who accepted marketing cookies.
* New: a guest buyer who accepted marketing cookies gets a visitor cookie at checkout, so their recommendations can be shown on a later visit.
* New: Elementor Pro forms get a "Smaily" action under Actions After Submit. Map the email and other fields to Smaily, choose newsletter signup or contact form with a marketing consent box, and optionally start a workflow.
* New: connecting Campaign Intelligence starts the product catalog import automatically. A notice lets you hold it back before anything is sent.
* Improved: each Campaign Intelligence automation shows what is really stored: Off, Test mode, Waiting for Smaily's confirmation, or Live. Smaily switches real sends on after your written confirmation.
* Improved: when you choose "All customers (legitimate interest)" for contact sync, the warning now names what soft opt-in requires.
* Improved: a line under the browse-tracking toggle says where consent comes from, and links to the WP Consent API plugin when it is missing.
* Changed: browse tracking and recommendations now count marketing consent only when the shopper said yes in a consent banner connected to the WP Consent API. A store that has the WP Consent API but no banner storing consent through it stops sending browse events until such a banner is set up.
* Fixed: a shopper's personalised-recommendations choice and the abandoned-cart purchase marker no longer create a new subscribed Smaily contact for an address Smaily does not have.
* Fixed: when Smaily answers "invalid data" (code 203), the Event Log row now fails at once with Smaily's message instead of showing as sent.
* Fixed: an abandoned-cart reminder for a cart of 10 products or fewer no longer keeps the "more than 10 products" flag from an earlier, larger cart.
* Fixed: a recommendation link without a campaign context no longer leaves an older context in place, so a sale after an email click is credited to the email.
* Security & privacy: Campaign Intelligence accepts a new connection only from Smaily's own address (https://intelligence.smaily.com). Existing connections keep working.
* Security & privacy: the storefront browse relay ignores identity hints sent by the browser, sends each batch in one short attempt, and always applies its per-address rate limit. A visitor cookie from your store is sent to Smaily only with the shopper's marketing consent.
* Security & privacy: the Elementor form action records the page address only for a page on your own store.
* Updated to the Smaily Campaign Intelligence API contract v1.12.0.

== Upgrade Notice ==

= 3.16.1 =
Fixes the Elementor Pro form action's "Other fields" editor, Smaily refusals shown as sent and cancelled imports that restarted; after setup, the Smaily Connect menu opens Settings. Safe update.

= 3.16.0 =
Adds store recommendations and an Elementor Pro form action; browse tracking and recommendations now need a yes stored by a consent banner through the WP Consent API, and if you use Campaign Intelligence, run Import existing data → Customers once after updating.

== Screenshots ==

1. Contact synchronisation — choose which contacts and customer fields are sent to Smaily.
2. Signup forms — collect subscribers through shortcode, Gutenberg, Elementor, the classic widget or Contact Form 7.
3. WooCommerce automations — connect welcome, first-order and abandoned-cart events to Smaily workflows.
4. Transactional emails — send order and shipping confirmations through a separate Smaily Transactional account.
5. Product RSS feed — configure which WooCommerce products are loaded into Smaily email templates.
6. Campaign Intelligence connection — manage the engine connection and import existing products, customers and orders.
