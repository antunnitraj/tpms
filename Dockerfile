############################################
# PHP 8.5 + nginx (serversideup) with the extensions TPMS needs
############################################
# Pinned: a new major of the base image changes defaults. Bump on purpose (README, "Updating").
FROM serversideup/php:8.5-fpm-nginx-v5.1.1 AS base

USER root
RUN install-php-extensions intl bcmath gd exif
USER www-data
WORKDIR /var/www/html

############################################
# Composer dependencies
############################################
FROM base AS vendor

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

############################################
# Filament theme (Tailwind via Vite)
############################################
FROM node:24-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY --from=vendor /var/www/html/vendor/filament ./vendor/filament
RUN npm run build

############################################
# Production image
############################################
FROM base

ENV PHP_OPCACHE_ENABLE=1 \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=600 \
    PHP_UPLOAD_MAX_FILE_SIZE=2048M \
    PHP_POST_MAX_SIZE=2048M \
    NGINX_CLIENT_MAX_BODY_SIZE=2048M \
    AUTORUN_ENABLED=true \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning

COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

USER root
COPY docker/entrypoint.d/40-tpms-app-key.sh /etc/entrypoint.d/40-tpms-app-key.sh
RUN chmod 755 /etc/entrypoint.d/40-tpms-app-key.sh
USER www-data

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan filament:assets \
    && mkdir -p storage/app/media storage/app/private/imports storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs
