# TaskWeaver controller image — FrankenPHP (worker mode).
#
# The Symfony kernel is loaded once and kept in memory across requests
# (FrankenPHP worker mode) instead of being rebuilt per request — the same
# setup penny-track / vital-pulse / preauth already use. FrankenPHP also
# embeds its web server (Caddy), so no Apache/PHP-FPM pair is needed.
#
# The controller holds secrets (LLM provider key, enrollment token) and runs
# the admin UI. The scheduler driver reuses this image with a custom
# command (see the scheduler service in docker-compose.yml).

# ── Stage 1: build the application (composer deps + prod cache) ────
FROM dunglas/frankenphp:1-php8.4 AS build

WORKDIR /app

# Composer manifests first for layer caching.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN install-php-extensions intl pdo pdo_sqlite \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && rm -rf var/cache/* var/log/* \
    && APP_ENV=prod APP_SECRET=build-secret bin/console cache:warmup || true \
    && rm -rf var/cache/*

# ── Stage 2: runtime ───────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4 AS runtime

RUN install-php-extensions intl pdo pdo_sqlite \
    && apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-taskweaver.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
COPY --from=build /app /app
RUN rm -rf var/cache/* var/log/*

# Startup: run migrations (and the dev/test-only seed), then hand off to
# FrankenPHP — or exec a custom command (scheduler driver) after migrating.
COPY docker/controller-entrypoint.sh /usr/local/bin/controller-entrypoint
RUN chmod +x /usr/local/bin/controller-entrypoint

# SQLite database lives on a volume; migrations run at startup.
# frankenphp listens on :80 via the base image once SERVER_NAME is set.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80

EXPOSE 80

ENTRYPOINT ["controller-entrypoint"]
# Default only; the override comes from docker/Caddyfile (custom command for
# the scheduler service still flows through the entrypoint).
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
