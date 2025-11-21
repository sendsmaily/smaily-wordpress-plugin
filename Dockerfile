ARG WORDPRESS_VERSION=6.8

FROM wordpress:${WORDPRESS_VERSION}

ARG user_id
ARG group_id
ARG username
ARG group

RUN <<EOF
    set -eux
    # Set up local user.
    if grep -q "${group}:" /etc/group; then \
        groupmod -g ${group_id} ${group}; \
    else \
        groupadd -f -g ${group_id} ${group}; \
    fi

    useradd -m -u ${user_id} -g ${group_id} ${username} -s /bin/bash

    # Add user to www-data group to get file access permissions.
    usermod -a -G www-data ${username}
EOF

# Install Composer.
RUN <<EOF
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        >&2 echo 'ERROR: Invalid installer checksum'
        rm composer-setup.php
        exit 1
    fi
    php composer-setup.php
    php -r "unlink('composer-setup.php');"
    mv composer.phar /usr/local/bin/composer
EOF

# Install required packages.
RUN apt-get update \
    && apt-get install -y \
    g++ \
    libicu-dev \
    less \
    gettext \
    unzip \
    zip \
    wget \
    zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

# Install node and npm.
ARG NODE_VERSION=22.13.1
RUN wget -O /tmp/node.tar.xz "https://nodejs.org/dist/v22.13.1/node-v${NODE_VERSION}-linux-x64.tar.xz" \
    && mkdir /tmp/node && tar -xvf /tmp/node.tar.xz -C /tmp/node --strip-components=1 \
    && mv /tmp/node/bin/* /usr/local/bin \
    && mv /tmp/node/include/* /usr/local/include \
    && mv /tmp/node/lib/* /usr/local/lib \
    && mv /tmp/node/share/* /usr/local/share

# Compile and install PHP transliterator.
RUN docker-php-ext-configure intl \
    && docker-php-ext-install intl

# Install Contact Form 7.
ARG CF7_VERSION="latest-stable"
RUN wget -O /tmp/cf7.zip "https://downloads.wordpress.org/plugin/contact-form-7.${CF7_VERSION}.zip" \
    && unzip /tmp/cf7.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/cf7.zip

# Install Really Simple CAPTCHA.
ARG REALLY_SIMPLE_CAPTCHA_VERSION="latest-stable"
RUN wget -O /tmp/rsc.zip "https://downloads.wordpress.org/plugin/really-simple-captcha.${REALLY_SIMPLE_CAPTCHA_VERSION}.zip" \
    && unzip /tmp/rsc.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/rsc.zip

# Install Plugin Check. Always check with the latest version.
ENV PLUGIN_CHECK_VERSION="latest-stable"
RUN wget -O /tmp/pcp.zip "https://downloads.wordpress.org/plugin/plugin-check.${PLUGIN_CHECK_VERSION}.zip" \
    && unzip /tmp/pcp.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/pcp.zip

# WooCommerce
ARG WOOCOMMERCE_VERSION="latest-stable"
RUN wget -O /tmp/wc.zip "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" \
    && unzip /tmp/wc.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/wc.zip

# MailHog
ARG MAILHOG_VERSION="latest-stable"
RUN wget -O /tmp/mailhog.zip "https://downloads.wordpress.org/plugin/wp-mailhog-smtp.${MAILHOG_VERSION}.zip" \
    && unzip /tmp/mailhog.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/mailhog.zip
