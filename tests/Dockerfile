# Testmiljöns webbserver: samma bas som produktionen (php:8.2.35-apache) med samma tillägg.
FROM php:8.2.35-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev mariadb-client \
 && docker-php-ext-install pdo pdo_mysql mysqli zip && a2enmod rewrite \
 && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
 && printf 'display_errors=Off\nlog_errors=On\nerror_reporting=E_ALL & ~E_DEPRECATED\n' > /usr/local/etc/php/conf.d/errors.ini \
 && rm -rf /var/lib/apt/lists/*
