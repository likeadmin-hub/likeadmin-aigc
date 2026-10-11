FROM php:8.0-cli
# Exercise the actual old updater with disposable MySQL, before the PHP 8.2 gate.
RUN docker-php-ext-install pdo_mysql
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list && apt-get update && apt-get install -y --no-install-recommends unzip=6.0-26+deb11u1 && rm -rf /var/lib/apt/lists/*
