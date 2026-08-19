FROM php:8.2-fpm-bookworm AS app

ARG DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        ffmpeg \
        gosu \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mysqli \
        pcntl \
        pdo_mysql \
        soap \
        sockets \
        zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
COPY docker/php/php.ini /usr/local/etc/php/conf.d/likeadmin.ini
COPY docker/php/entrypoint.sh /usr/local/bin/likeadmin-entrypoint
COPY docker/php/scheduler.sh /usr/local/bin/likeadmin-scheduler
COPY docker/php/healthcheck.php /usr/local/bin/likeadmin-healthcheck.php
COPY docker/php/initialize.php /usr/local/bin/likeadmin-initialize.php

RUN set -eux; \
    composer dump-autoload --no-dev --optimize; \
    mkdir -p runtime/sessions public/uploads public/storage public/qrcode; \
    touch config/install.lock; \
    chown -R www-data:www-data runtime public/uploads public/storage public/qrcode; \
    chmod 755 /usr/local/bin/likeadmin-entrypoint /usr/local/bin/likeadmin-scheduler

ENTRYPOINT ["likeadmin-entrypoint"]
CMD ["fpm"]

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD ["php", "/usr/local/bin/likeadmin-healthcheck.php"]

FROM nginx:1.27-alpine AS web

COPY public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

RUN mkdir -p /var/www/html/public/uploads /var/www/html/public/storage /var/www/html/public/qrcode

HEALTHCHECK --interval=15s --timeout=3s --start-period=10s --retries=5 \
    CMD wget -q -O - http://127.0.0.1/healthz | grep -q '^ok$'
