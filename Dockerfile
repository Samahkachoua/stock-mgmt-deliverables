# Production image for Render (or any Docker host): Apache + PHP 8.3, with the
# Vite assets built in a separate Node stage. docker/start.sh runs at boot.

# --- Assets: Tailwind scans the Blade views, so the whole app is copied in ---
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
# --ignore-scripts: skips the Supabase CLI's binary download (local dev only).
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

# --- PHP dependencies, without dev packages ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# --- Runtime ---
FROM php:8.3-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
# pdo_pgsql: Supabase Postgres. gd, zip: Excel imports/exports and mPDF. intl, bcmath: formatting, quantities.
RUN install-php-extensions pdo_pgsql pgsql gd zip intl bcmath opcache \
    && a2enmod rewrite headers \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -f public/hot \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/start.sh

ENV PORT=8080
EXPOSE 8080

CMD ["docker/start.sh"]
