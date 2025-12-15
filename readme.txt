=== Smaily Connect ===
Contributors: sendsmaily, kaarel
Tags: smaily, newsletter, email, mail, marketing
Requires PHP: 7.0
Requires at least: 6.0
Tested up to: 6.8
WC tested up to: 9.6.1
Stable tag: 1.6.0
License: GPLv3 or later

The Smaily Connect plugin integrates Contact Form 7 and WooCommerce, offering a complete email marketing and automation solution.

== Description ==

**Smaily Connect – The Only Email Marketing Plugin You Need!**

Transform your **WordPress website, WooCommerce store, Contact Form 7 and Elementor** into an **email marketing powerhouse** with Smaily – the all-in-one plugin designed to **automate your marketing, grow your audience, and drive more sales effortlessly**.

**Why Smaily Connect?**

**Turn Visitors into Subscribers** – Capture leads from **every touchpoint** – your website, WooCommerce store, and contact forms – all in one seamless flow.

**Automate Like a Pro** – Send high-converting emails effortlessly: welcome emails and abandoned cart reminders – **without lifting a finger**.

**Smart Form Integration** – Sync your **Contact Form 7** submissions directly to your Smaily lists for a frictionless email collection experience.

**Elementor Integration** – Build beautiful newsletter sign-up forms right inside Elementor using our dedicated widget!

**Smarter Email Campaigns** – Segment your audience and send **relevant offers, tailored product updates, and engaging content** that keeps subscribers interested and active.

**Easy, Fast & Code-Free Setup** – No tech skills needed! Just **install, activate, and start engaging** your audience instantly.

= Documentation & Support =

For documentation, feature requests, and support, visit our [Help Center](https://smaily.com/help/user-manuals/).

= External services =

This plugin uses [Smaily Public API](https://smaily.com/help/api/) to communicate with your Smaily account. This is needed to establish a connection and transfer information between your WordPress site and your Smaily account. The plugin uses the API for following functionality:

- validating Smaily account connection with API key
- listing available automation workflows
- triggering automation workflows on form submissions and during sending abandoned cart reminders
- managing user subscription status during subscriber synchronization
- updating user subscription status when unsubscribing from newsletters

You can manage how much information is shared between your WordPress site and Smaily account by configuring the plugin settings.

Privacy Policy: [Smaily Privacy Policy](https://smaily.com/privacy-policy/)
Terms of Service: [Smaily Terms of Service](https://smaily.com/terms-of-service/)

= Contribute =

Contribute to the development via [GitHub](https://github.com/sendsmaily/smaily-wordpress-plugin). We welcome new issues and pull requests.

== Installation ==

1. Upload the plugin files to your site's `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.

== Changelog ==

= 1.6.0 =

Added support for adding a tax rate to the RSS-feed product prices. This allows to change the tax rate used in the feed to match the tax rate used in Smaily email templates. This is especially useful for stores that want to target customers in different regions with different tax rates in their email campaigns.

Smaily Elementor widget now supports adding custom hidden fields to the subscription form. This allows to set custom fields for subscribers added via the Elementor widget allowing to better segment the subscribers in Smaily.

= 1.5.1 =

Added a label to the hidden fields section in the Smaily subscription block settings for better clarity.

= 1.5.0 =

You can now customize the hidden fields on the Smaily subscription block form. This allows to set custom fields for subscribers added via the block form allowing to better segment the subscribers in Smaily.

= 1.4.3 =

Added more hooks where the Smaily abandoned cart record is deleted. The current approach might on some occasions leave abandoned cart records lingering around even when the user has made a purchase.

= 1.4.2 =
Fixes an issue where Elementor integration assets were being excluded from the plugin package. This caused the Elementor widget styles to be missing after installation. The packaging patterns have been updated to ensure all necessary assets are included.

= 1.4.1 =
Fixes an issue where the abandoned cart cutoff time minimum value was 30 minutes instead of 10 minutes as intended. The minimum cutoff time has been corrected to 10 minutes, allowing users to set a lower threshold for considering carts as abandoned.

= 1.4.0 =

Improved RSS-feed items to show prices including taxes. Also added support for Discount Rules for WooCommerce plugin to correctly show discounted prices in the feed and in the abandoned cart reminders.

= 1.3.3 = 

Improved the Elementor widget performance by reducing the number of API calls made during the rendering process.

= 1.3.2 =

Fixed a bug where the RSS-feed `pubDate` was not correctly formatted, which could lead to issues while importing sorted products into Smaily templates. Now the `pubDate` is formatted according to the RFC 822 standard, ensuring compatibility with RSS parsers.

= 1.3.1 =

- Improved the admin notice when the Smaily API credentials are invalid. Now the notice is rendered closer to the credentials input fields for better visibility.
- Improved autoresponder listing function validation to handle edge cases and ensure robust performance.

= 1.3.0 =

Improved the Contact Form 7 integration by allowing user to configure each form individually.

= 1.2.4 =

Render admin notices outside the form element to ensure proper display and avoid potential conflicts with form submission.

= 1.2.3 =

Load the plugin text domain in the `init` action. This complies with the WordPress 6.7+ plugin development standards and ensures that the plugin translations are loaded correctly.

= 1.2.2 =

Fixed RSS feed product query by removing random ordering. The combination of random ordering and query limits could result in empty product feeds on subsequent requests, causing RSS parser failures.

= 1.2.1 =

Fixed a bug where abandoned cart reminder emails were not sent due to a syntax error in the query statement building process.

= 1.2.0 =

Added a new block component for embedding Smaily Landing Pages.

= 1.1.0 =

Introduced a new Elementor widget that makes it easy to add a Smaily subscription form when building pages with Elementor.


= 1.0.0 =
* Combined Smaily for Contact Form 7, Smaily for WP, and Smaily for WooCommerce into a single plugin for a streamlined experience.

== Upgrade Notice ==

= 1.0.0 =
If upgrading from individual Smaily plugins to the combined version, please review your settings to ensure all integrations are correctly configured.

== Screenshots ==

1. Smaily Connect Admin View
2. Getting Started
3. Subscriber Synchronization
4. Abandoned Cart Reminder Emails
5. Import Products To Templates From RSS-Feed
6. Opt-In Form Block
7. Integrate With Contact Form 7
8. Smaily Elementor Opt-In Form
