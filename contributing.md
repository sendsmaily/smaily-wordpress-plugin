First off, thanks for taking the time to contribute!

# Table of contents

- [Getting started](#getting-started)
- [Internals](#internals)
  - [Structure of the repository](#structure-of-the-repository)
- [Development](#development)
  - [Starting the environment](#starting-the-environment)
  - [Stopping the environment](#stopping-the-environment)
  - [Resetting the environment](#resetting-the-environment)
  - [Developing the plugin](#developing-the-plugin)
    - [Development options](#development-options)
    - [Code Sniffing and Linting](#code-sniffing-and-linting)

# Getting started

The development environment requires [Docker](https://docs.docker.com/) and [Docker Compose](https://docs.docker.com/compose/) to run. Please refer to the official documentation for step-by-step installation guide.

Clone the repository:

    $ git clone git@github.com:sendsmaily/smaily-wordpress-plugin.git

Next, change your working directory to the local repository:

    $ cd smaily-wordpress-plugin

First you need to build the application and reflect the local user with correct permissions into the container.
The `user.sh` script will read your user id, group id, names and store it in `.env` file to provide context for the build command.

    $ ./user.sh
    $ docker compose build

And boot up the environment:

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

You can develop the plugin locally in the current folder and the changes are reflected in the development container. This is beneficial when doing simple changes that doesn't require intellisense for WordPress related functions.

### VS Code Remote Containers

In order to get intellisense for WordPress related functions and navigate through the whole WordPress installation you can use [VS Code Remote Containers](https://code.visualstudio.com/docs/devcontainers/containers). The build process reflected your local user into the container to maintain correct file permissions when editing files in the container. We have also prepared a [VS Code Workspace](https://code.visualstudio.com/docs/editor/workspaces) that includes two folders:

- html - root of the WordPress installation
- smaily - root of the plugin

This allows to get type hints for WordPress related functions while developing the application and ease of access to plugin files. You can use the `File->Open Workspace from File...` command in the editor to open the Workspace. You can find the workspace file in:

    /var/www/html/wp-content/plugins/smaily/.vscode/container.code-workspace

## Stopping the environment

Environment can be stopped by executing:

    $ docker compose down --remove-orphans

## Resetting the environment

If you need to reset the installation, just simply delete environment's Docker volumes. Easiest way to achieve this is by running:

    $ docker compose down --remove-orphans --volumes

## Developing the plugin

### Development options

This module can be developed both locally and in a containerized environment. Visual Studio Code offers support for developing the plugin inside a remote container, providing the following benefits:

- IntelliSense Support: Access to IntelliSense for WordPress internal functions, enhancing the development experience.
- Seamless Environment: The remote container replicates the WordPress environment, reducing compatibility issues.

For quick edits or when a remote container is not required, you can also modify the files directly on your local machine. These changes will be immediately reflected in the running WordPress instance.

### Code Sniffing and Linting

This repository uses PHP CodeSniffer with specific rules defined in the `phpcs.xml` file. To run the code sniffer locally, you need to have [Composer](https://getcomposer.org/) installed. The remote container environment is already set up with Composer.

To install PHP CodeSniffer and the required coding standards, run the following command:

```
$ composer install
```

> **Note!** When running the command inside container the `vendor` directory is created with `root` permissions. This may cause issues when executing Composer-related functions locally afterwards.

You can check for linting errors by executing:

```
$ composer run lint
```

Some reported errors can be automatically fixed. To apply these fixes, run:

```
$ composer run format
```
