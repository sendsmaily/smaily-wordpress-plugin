# Smaily Connect

The Smaily plugin integrates Contact Form 7 and WooCommerce, offering a complete email marketing and automation solution.

## Description

**Smaily Connect – The Only Email Marketing Plugin You Need!**

Transform your **WordPress website, WooCommerce store, Contact Form 7 and Elementor** into an **email marketing powerhouse** with Smaily – the all-in-one plugin designed to **automate your marketing, grow your audience, and drive more sales effortlessly**.

**Why Smaily Connect?**

**Turn Visitors into Subscribers** – Capture leads from **every touchpoint** – your website, WooCommerce store, and contact forms – all in one seamless flow.

**Automate Like a Pro** – Send high-converting emails effortlessly: welcome emails and abandoned cart reminders – **without lifting a finger**.

**Smart Form Integration** – Sync your **Contact Form 7** submissions directly to your Smaily lists for a frictionless email collection experience.

**Elementor Integration** – Build beautiful newsletter sign-up forms right inside Elementor using our dedicated widget!

**Smarter Email Campaigns** – Segment your audience and send **relevant offers, tailored product updates, and engaging content** that keeps subscribers interested and active.

**Easy, Fast & Code-Free Setup** – No tech skills needed! Just **install, activate, and start engaging** your audience instantly.

## Documentation & Support

For documentation, feature requests, and support, visit our [Help Center](https://smaily.com/help/user-manuals/).

## External Services

This plugin uses [Smaily Public API](https://smaily.com/help/api/) to communicate with your Smaily account. This is needed to establish a connection
and transfer information between your WordPress site and your Smaily account. The plugin uses the API for following functionality:

- validating Smaily account connection with API key
- listing available automation workflows
- triggering automation workflows on form submissions and during sending abandoned cart reminders
- managing user subscription status during subscriber synchronization
- updating user subscription status when unsubscribing from newsletters

You can manage how much information is shared between your WordPress site and Smaily account by configuring the plugin settings.

Privacy Policy: [Smaily Privacy Policy](https://smaily.com/privacy-policy/)

Terms of Service: [Smaily Terms of Service](https://smaily.com/terms-of-service/)


## Contribute

Contribute to the development via [GitHub](https://github.com/sendsmaily/smaily-wordpress-plugin). We welcome new issues and pull requests.

## Installation

1. Upload the plugin files to your site's `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.


## Subscriber Synchronization

The subscriber synchronization runs once per day and is triggered by a WordPress cron job.

### Two-way Automatic Synchronization

The two way automatic synchronization works as follows:
1. Users who have been unsubscribed from the newsletter in Smaily are also unsubscribed in WordPress.
2. Users who have subscribed status are now added to Smaily.

This means that the plugin will automatically synchronize the subscription status and the users data between WordPress and Smaily. Smaily will remain the master source of the data. You can notice that there is no step to import users from Smaily to WordPress. This is because the plugin is designed to push the data from WordPress to Smaily and not the other way around.

Additionally, the users who have been unsubscribed from the newsletter in Smaily are always unsubscribed in WordPress. This is to prevent the user from being added back to Smaily when they have unsubscribed with the unsubscribe link in the newsletter. To re-subscribe the user must use the subscription form or other real-time subscription method.

### Real-Time Synchronization

The real-time synchronization is triggered by the user's actions on the site. These happen instantly and do not require the WordPress cron job to run.

There are quite a few options available:
- subscriber uses the built-in Smaily newsletter subscription form (widget, shortcode, or block)
- subscriber checks the subscription checkbox during registration or checkout
- subscriber updates their account details and checks the subscription checkbox
- administrator updates the user's subscription status in the user's profile
- subscriber uses Contact Form 7 form that has been configured with Smaily

You can also use the Smaily landing pages to collect subscribers. The landing pages are not part of the plugin but you can use a button or a link to direct the user to the landing page.

## Abandoned Cart Reminder Emails

The abandoned cart actions are scheduled to run in every 15 minutes. This means that the abandoned carts are marked and notifications are sent in every 15 minutes. User can configure the time when cart is considered abandoned (cart cutoff time) in the plugin settings. The default cutoff time is 30 minutes. This means that the cart is considered abandoned if the user has not completed the purchase in 30 minutes after the last cart update.

As the cron job is scheduled to run in every 15 minutes and the actual time when the abandoned cart reminder is sent can be up to 15 minutes after the cart is considered abandoned. The abandoned cart reminder is sent only once per abandoned cart.

We recommend the default value of 30 minutes for the delay. This is the sweet spot for most of the cases and offers a good balance between reminding the user and not spamming them and offering the largest potential for conversion.

## Synchronization Values

This is a description of the values that are added to the user's meta data when synchronizing users between WordPress and Smaily. Some of these values are automatically added by the plugin, some of them can be configured in the plugin settings.

### Subscriber Synchronization

Automatically added values:
- `email` - user's email address
- `store` - store URL
- `language` - user's language

Optional values:
- `customer_group` - user's role
- `customer_id` - user's ID
- `first_name` - user's first name
- `first_registered` - user's registration date
- `last_name` - user's last name
- `nickname` - user's nickname
- `site_title` - site title (set in Settings > General)
- `birthday` - user's birthday in format YYYY-MM-DD
- `user_gender` - user's gender
- `user_phone` - user's phone number

### Abandoned Cart Synchronization

Automatically added values:
- `email` - user's email address
- `store` - store URL
- `language` - user's language
- `is_abandoned_cart` - true

Optional values:
- `first_name` - user's first name
- `last_name` - user's last name

Product values:

Up to 10 products can be added to the abandoned cart. The products follow the format `{attribute}_{number}` where `{number}` is the product number (1-10) and `{attribute}` is the product attribute. For example `product_name_1`, `product_name_2`, `product_name_3`, etc.

The following attributes are available:
- `product_name` - product name
- `product_description` - product description
- `product_sku` - product SKU
- `product_quantity` - product quantity
- `product_base_price` - product base price including tax and with currency
- `product_price` - product sale price including tax and with currency
- `product_image_url` - product image URL. Featured image is used. If not set the first image from the product gallery is used.
- `over_10_product` - true if there are more than 10 products in the cart


## Changelog

## 1.2.0

- Added a new block component for embedding Smaily Landing Pages.

### 1.1.0

- Added a Elementor widget for the Smaily subscription form.

### 1.0.0
- Combined Smaily for Contact Form 7, Smaily for WP, and Smaily for WooCommerce into a single plugin for a streamlined experience.
