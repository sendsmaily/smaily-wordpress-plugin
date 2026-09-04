First off, thanks for taking the time to contribute!

# Table of contents

- [Getting started](#getting-started)
  - [Running the minimum supported version](#running-the-minimum-supported-version)
- [Internals](#internals)
  - [Structure of the repository](#structure-of-the-repository)
- [Development](#development)
  - [Starting the environment](#starting-the-environment)
  - [Stopping the environment](#stopping-the-environment)
  - [Resetting the environment](#resetting-the-environment)
  - [Running WP-CLI commands](#running-wp-cli-commands)
  - [Inspecting outgoing mail](#inspecting-outgoing-mail)
  - [Developing the plugin](#developing-the-plugin)
    - [Installing dependencies](#installing-dependencies)
    - [Translations](#translations)
    - [Code Sniffing and Linting](#code-sniffing-and-linting)

# Getting started

The development environment is built on [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), which provisions a local WordPress site in Docker. You need [Docker](https://docs.docker.com/) and [Node.js](https://nodejs.org/) (which provides `npx`) installed. Please refer to the official documentation for step-by-step installation guides.

Clone the repository:

    $ git clone git@github.com:sendsmaily/smaily-wordpress-plugin.git

Next, change your working directory to the local repository:

    $ cd smaily-wordpress-plugin

Boot up the environment:

    $ composer start

This downloads WordPress, maps the plugin into the site, activates it and installs the following plugins:
- [WooCommerce](https://wordpress.org/plugins/woocommerce/)
- [Contact Form 7](https://wordpress.org/plugins/contact-form-7/)
- [Really Simple Captcha](https://wordpress.org/plugins/really-simple-captcha/)

Once it finishes, the site is available at `http://localhost:8888` and the administration interface at `http://localhost:8888/wp-admin`. Sign in with the default credentials `admin` / `password`.


## Running the minimum supported version

The plugin should be compatible with both the latest version of WordPress and the minimum supported version. Both stacks are committed as separate wp-env configurations:

- `.wp-env.json` — the default stack: latest WordPress, WooCommerce, Contact Form 7 and Really Simple Captcha on PHP 8.3.
- `.wp-env.min.json` — the minimum supported stack: WordPress 6.6, WooCommerce 7.7.2, Contact Form 7 5.7.7 and Really Simple Captcha 2.1 on PHP 8.0.

Start the minimum supported stack with:

    $ composer start:min

Both stacks listen on the same port (8888), so only one can run at a time. A single `composer stop` stops whichever stack is running, so switching is always stop-then-start. To switch from the default stack to the minimum one:

    $ composer stop
    $ composer start:min

When the minimum stack is running, WP-CLI commands must be pointed at its configuration with `--config`:

    $ npx @wordpress/env --config .wp-env.min.json run cli wp core version
    $ npx @wordpress/env --config .wp-env.min.json run cli wp plugin get woocommerce --field=version

To switch back to the default stack:

    $ composer stop
    $ composer start

# Internals

## Structure of the repository

The repository is split into multiple parts:

- `admin` - administrator interface related components;
- `blocks` - Gutenberg blocks components;
- `cf7` - Contact Form 7 plugin integration;
- `gfx` - illustrations & media;
- `includes` - functionality separated into class based components;
- `languages` - translations;
- `logs` - folder to store plugin logs;
- `migrations` - database migrations during plugin upgrade;
- `public` - public frontend interface related components;
- `woocommerce` - WooCommerce plugin integration;


# Development

Documentation about WordPress coding standards and plugin development can be found in the [WordPress development resources](https://developer.wordpress.org/).

The plugin source is live-mapped into the running site, so edits to the files in this repository are reflected in the environment immediately — no rebuild or copy step is needed for PHP changes.

## Starting the environment

You can start the default environment by executing:

    $ composer start

To start the minimum supported stack instead, run `composer start:min` (see [Running the minimum supported version](#running-the-minimum-supported-version)).

> **Note!** Make sure you do not have any other process listening on port 8888 (the development site). The default and minimum stacks share this port, so only one can run at a time.

## Stopping the environment

The environment can be stopped by executing:

    $ composer stop

This stops whichever stack is running — the default or the minimum supported one.

## Resetting the environment

If you need to reset the WordPress installation (database and uploads) while keeping the downloaded WordPress and plugins, run:

    $ npx @wordpress/env clean all

To tear the environment down completely, removing all of its Docker containers, volumes and downloaded sources:

    $ npx @wordpress/env destroy

## Running WP-CLI commands

You can run [WP-CLI](https://wp-cli.org/) commands against the environment through the `cli` container, for example:

    $ npx @wordpress/env run cli wp plugin list
    $ npx @wordpress/env run cli wp option get siteurl

## Inspecting outgoing mail

The environment runs with `WP_DEBUG` and `WP_DEBUG_LOG` enabled, so PHP notices and anything written through the WordPress logging facilities — including diagnostics around outgoing mail — are appended to `wp-content/debug.log` inside the site. There is no rendered-mail inbox; the debug log is the place to inspect mail-related activity.

View the log with:

    $ npx @wordpress/env run cli tail -f wp-content/debug.log

## Developing the plugin

### Installing dependencies

The plugin is packaged during the release process. Packaging includes installing dependencies, building block components, compiling translations and everything else required to get the source code ready for actual plugin usage.

While developing you need to run these actions when initially setting up the environment and after updating resources.

**Composer modules**

Composer is the package manager for PHP. It is used to install and manage dependencies for the plugin. To install Composer dependencies, run:

    $ composer install

This command allows you to run further commands that depend on Composer packages such as compiling translations or running code sniffing.

**Block modules**

Installing block component dependencies can be done using the following command. This will install all the required npm packages for every block component.

    $ composer run install-block-modules

You also need to build the block components from the source code. This creates a `build` folder inside each block component folder. The `build` folder contains the compiled files used by the plugin, and WordPress references these folders when looking for block components.

    $ composer run build

When developing a block component you can also watch for file changes and automatically rebuild the component when a file changes. Run the following command in the block component folder you are currently developing. For example, running the watch command in `blocks/checkout-optin` will watch for file changes in that folder and rebuild the component when a file is changed.

    $ npm run start

### Translations

Plugin translations are stored in the `/languages` folder. These include `smaily-connect.pot` and `smaily-connect-<locale>.po` files. However, these files are not readable by the WordPress translation loading system. Instead, they provide a basis for building machine readable translation files `*.mo`.

To compile the machine readable translation files, run:

    $ composer run compile-translations

When making changes to the translation files, you need to update the translation template file `smaily-connect.pot` to include the changed strings:

    $ composer run extract-text-domain

You can translate the plugin to different languages. The most convenient way to do this is by using a translation editor plugin such as [Loco Translate](https://wordpress.org/plugins/loco-translate/). This plugin allows you to edit the translation files directly from the WordPress administration interface.

### Code Sniffing and Linting

This repository uses PHP CodeSniffer with specific rules defined in the `phpcs.xml.dist` file. To run the code sniffer locally, you need to have [Composer](https://getcomposer.org/) installed.

You can check for linting errors by executing:

```
$ composer run lint
```

Some reported errors can be automatically fixed. To apply these fixes, run:

```
$ composer run format
```
