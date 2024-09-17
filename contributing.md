First off, thanks for taking the time to contribute!

# Table of contents

- [Getting started](#getting-started)
- [Internals](#internals)
  - [Structure of the repository](#structure-of-the-repository)
- [Development](#development)
  - [Starting the environment](#starting-the-environment)
  - [Stopping the environment](#stopping-the-environment)
  - [Resetting the environment](#resetting-the-environment)

# Getting started

The development environment requires [Docker](https://docs.docker.com/) and [Docker Compose](https://docs.docker.com/compose/) to run. Please refer to the official documentation for step-by-step installation guide.

Clone the repository:

    $ git clone git@github.com:sendsmaily/smaily-wordpress-plugin.git

Next, change your working directory to the local repository:

    $ cd smaily-wordpress-plugin 

And run the environment:

    $ docker compose up -d

During first run WordPress installation wizard guides you through the setup process. After completing the installation, the site can be accessed from `http://localhost:8080` and the administration interface from `http://localhost:8080/wp-admin` URL.

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

## Starting the environment

You can run the environment by executing:

    $ docker compose up -d

> **Note!** Make sure you do not have any other process(es) listening on ports 8080, 8888 and 8025. Port 8888 is used by phpmyadmin and port 8025 is used by the mailhog service.

## Stopping the environment

Environment can be stopped by executing:

    $ docker compose down --remove-orphans

## Resetting the environment

If you need to reset the installation, just simply delete environment's Docker volumes. Easiest way to achieve this is by running:

    $ docker compose down --remove-orphans --volumes
