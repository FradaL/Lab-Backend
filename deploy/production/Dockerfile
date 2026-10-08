# syntax=docker/dockerfile:1.7

FROM composer:2.8 AS vendor-base
WORKDIR /build
COPY laravel/composer.json laravel/composer.lock ./

FROM vendor-base AS vendor
RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts

FROM vendor-base AS vendor-dev
RUN composer install \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts

FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j1 \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/production/docker/php/production.ini /usr/local/etc/php/conf.d/production.ini
COPY deploy/production/docker/php/www.conf /usr/local/etc/php-fpm.d/zz-production.conf

WORKDIR /var/www/html

FROM php-base AS app-source
COPY --chown=www-data:www-data laravel/ .
RUN mkdir -p \
        bootstrap/cache \
        storage/api-docs \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

FROM app-source AS app
COPY --from=vendor --chown=www-data:www-data /build/vendor ./vendor
RUN php artisan package:discover --ansi \
    && php artisan l5-swagger:generate \
    && php artisan storage:link

USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM app-source AS seed
COPY --from=vendor-dev --chown=www-data:www-data /build/vendor ./vendor
RUN php artisan package:discover --ansi
USER www-data
ENTRYPOINT ["php", "artisan"]

FROM nginx:1.29-alpine AS web
COPY deploy/production/docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
RUN mkdir -p /var/www/html/storage/app/public
