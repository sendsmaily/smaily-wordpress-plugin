# Smaily

Comprehensive Smaily integration for WordPress, WooCommerce, and Contact Form 7, offering seamless newsletter sign-up and automation features across your site.

## Features

- **Simple integration** with Contact Form 7 elements.
- **WordPress Newsletter Subscribers** for easy sign-up.
- **WooCommerce Newsletter Subscribers** with various subscription options.
- **Abandoned cart reminder emails** for WooCommerce.
- **Two-way synchronization** between Smaily and WooCommerce.
- **Subscription Widget** available for easy integration on any page.

## Documentation & Support

For documentation, feature requests, and support, visit our [Help Center](https://smaily.com/help/user-manuals/).

## External services

This plugin uses [Smaily Public API](https://smaily.com/help/api/) to communicate with your Smaily account. This is needed to establish a connection
and transfer information between your WordPress site and your Smaily account. The plugin uses the API for following functionality:

- validating Smaily account connection with API key
- listing available automation workflows
- triggering automation workflows on form submissions and during sending abandoned cart reminders
- managing user subscription status during customer synchronization
- updating user subscription status when unsubscribing from newsletters

You can manage how much information is shared between your WordPress site and Smaily account by configuring the plugin settings.

Privacy Policy: [Smaily Privacy Policy](https://smaily.com/privacy-policy/)

Terms of Service: [Smaily Terms of Service](https://smaily.com/terms-of-service/)


## Contribute

Contribute to the development via [GitHub](https://github.com/sendsmaily/smaily-wordpress-plugin). We welcome new issues and pull requests.

## Installation

1. Upload the plugin files to your site's `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.

## Synchronization values

This is a description of the values that are added to the user's meta data when synchronizing users between WordPress and Smaily. Some of these values are automatically added by the plugin, some of them can be configured in the plugin settings.

### Customer synchronization

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

### Abandoned cart synchronization

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

### 1.0.0
- Combined Smaily for Contact Form 7, Smaily for WP, and Smaily for WooCommerce into a single plugin for a streamlined experience.

### Previous Entries
Refer to individual plugin changelogs for historical updates.

## Upgrade Notice

### 1.0.0
If upgrading from individual Smaily plugins to the combined version, please review your settings to ensure all integrations are correctly configured.
