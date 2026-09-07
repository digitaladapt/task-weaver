# TaskWeaver controller image.
#
# PHP-FPM-less: Apache + mod_php via the official php:apache image (the app
# is a classic Symfony app; no need for two processes). The controller holds
# secrets (LLM provider key, enrollment token) and runs the admin UI.

FROM php:8.4-apache-bookworm

# Runtime deps + PHP extensions required by the stack (composer.json).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install intl pdo pdo_sqlite \
    && a2enmod rewrite

# Composer from the official image (checksum-pinned copy).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# App source (composer files first for layer caching).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && rm -rf var/cache/* var/log/* \
    && APP_ENV=prod APP_SECRET=build-secret bin/console cache:warmup || true \
    && rm -rf var/cache/* \
    && chown -R www-data:www-data var public

# SQLite database lives on a volume; migrations run at startup.
ENV APP_ENV=prod

# Apache DocumentRoot → public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Startup: run migrations, then hand off to Apache.
COPY docker/controller-entrypoint.sh /usr/local/bin/controller-entrypoint
RUN chmod +x /usr/local/bin/controller-entrypoint
ENTRYPOINT ["controller-entrypoint"]