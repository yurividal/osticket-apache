FROM php:7.4-apache
ENV OSTICKET_VERSION=v1.15.4

ADD ./install.php /
ADD ./install.sh /

# For debugging purposes, do not merge the different RUN steps

# We need the LDAP extension; we don't need to keep libldap2-dev
# Clean up apt-get after each layer to keep layers small
RUN apt-get update && \
	apt-get install -y libldap2-dev && \
	rm -rf /var/lib/apt/lists/* && \
	docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/ && \
	docker-php-ext-install ldap && \
	apt-get purge -y --auto-remove libldap2-dev

# Configure opcache. In development we may want to override PHP_OPCACHE_VALIDATE_TIMESTAMPS
RUN docker-php-ext-install opcache
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS="0" \ 
	PHP_OPCACHE_MAX_ACCELERATED_FILES="10000" \
	PHP_OPCACHE_MEMORY_CONSUMPTION="256" \
	PHP_OPCACHE_MAX_WASTED_PERCENTAGE="10"
COPY ./opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# MySQLi
RUN docker-php-ext-install mysqli

# gdlib
RUN apt-get update && \
	apt-get install -y libpng-dev && \
	rm -rf /var/lib/apt/lists/* && \
	docker-php-ext-install gd

# IMAP
RUN apt-get update && \
	apt-get install -y libc-client-dev libkrb5-dev && \
	rm -rf /var/lib/apt/lists/* && \
	docker-php-ext-configure imap --with-kerberos --with-imap-ssl && \
	docker-php-ext-install imap

# intl
RUN apt-get update && \
	apt-get install -y libicu-dev wget nano git-core && \
	rm -rf /var/lib/apt/lists/* && \
	docker-php-ext-configure intl && \
	docker-php-ext-install intl

# apcu
RUN pecl install apcu && \
	docker-php-ext-enable apcu

# zip
RUN apt-get update && \
	apt-get install -y libzip-dev zip && \
	rm -rf /var/lib/apt/lists/* && \
	docker-php-ext-install zip

# Installing cron for cronjobs
RUN apt-get update && \
	apt-get install -y cron && \
	rm -rf /var/lib/apt/lists/*
COPY ./osticketcron /etc/cron.d/osticketcron


COPY ./php.ini "$PHP_INI_DIR/php.ini"


# Install PHP extension installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod uga+x /usr/local/bin/install-php-extensions && sync

# Install PHP extensions
RUN install-php-extensions pdo_mysql

# Enable Apache's mod_rewrite
RUN a2enmod rewrite

RUN install-php-extensions gd imap xml json mbstring phar intl fileinfo zip apcu opcache mysqli ldap sockets

RUN git clone -b ${OSTICKET_VERSION} --depth 1 https://github.com/osTicket/osTicket.git \
	&& cd osTicket \
	&& mv /install.php setup/ \
	&& mv /install.sh setup/ \
	&& php manage.php deploy -sv /var/www/html/

RUN wget -nv -O /var/www/html/include/i18n/fr.phar https://s3.amazonaws.com/downloads.osticket.com/lang/fr.phar \
	&& wget -nv -O /var/www/html/include/i18n/ar.phar https://s3.amazonaws.com/downloads.osticket.com/lang/ar.phar \
	&& wget -nv -O /var/www/html/include/i18n/pt_BR.phar https://s3.amazonaws.com/downloads.osticket.com/lang/pt_BR.phar \
	&& wget -nv -O /var/www/html/include/i18n/it.phar https://s3.amazonaws.com/downloads.osticket.com/lang/it.phar \
	&& wget -nv -O /var/www/html/include/i18n/es_ES.phar https://s3.amazonaws.com/downloads.osticket.com/lang/es_ES.phar \
	&& wget -nv -O /var/www/html/include/i18n/de.phar https://s3.amazonaws.com/downloads.osticket.com/lang/de.phar \
	&& wget -nv -O /var/www/html/include/plugins/auth-ldap.phar https://s3.amazonaws.com/downloads.osticket.com/plugin/auth-ldap.phar \
	&& wget -nv -O /var/www/html/include/plugins/storage-fs.phar https://s3.amazonaws.com/downloads.osticket.com/plugin/storage-fs.phar

RUN chmod +x /var/www/html/setup/install.sh

# Run both apache2-frontend as well as the cron daemon
ENTRYPOINT ["/bin/bash", "-c", "/var/www/html/setup/install.sh; chmod 644 /etc/cron.d/osticketcron; cron & apache2-foreground"]

# Make /var/www/html a recommended volume
VOLUME ["/var/www/html", "/var/www/attachments"]
EXPOSE 80