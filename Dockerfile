FROM wordpress:6.6.2

# Install required packages.
RUN apt-get update \
    && apt-get install -y \
    g++ \
    libicu-dev \
    unzip \
    wget \
    zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

# Compile and install PHP transliterator.
RUN docker-php-ext-configure intl \
    && docker-php-ext-install intl

# Install Contact Form 7.
ENV CF7_VERSION="5.9.8"
RUN wget -O /tmp/cf7.zip "https://downloads.wordpress.org/plugin/contact-form-7.${CF7_VERSION}.zip" \
    && unzip /tmp/cf7.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/cf7.zip

# Install Really Simple CAPTCHA.
ENV RSC_VERSION="2.3"
RUN wget -O /tmp/rsc.zip "https://downloads.wordpress.org/plugin/really-simple-captcha.${RSC_VERSION}.zip" \
    && unzip /tmp/rsc.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/rsc.zip

# Install Plugin Check.
ENV PCP_VERSION="1.1.0"
RUN wget -O /tmp/pcp.zip "https://downloads.wordpress.org/plugin/plugin-check.${PCP_VERSION}.zip" \
    && unzip /tmp/pcp.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/pcp.zip

# WooCommerce
ENV WC_VERSION="9.2.3"
RUN wget -O /tmp/wc.zip "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" \
    && unzip /tmp/wc.zip -d  /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/wc.zip
