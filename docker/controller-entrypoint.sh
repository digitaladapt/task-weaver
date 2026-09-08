#!/bin/sh
# Controller container entrypoint: migrate then serve — or, when a custom
# command is given (e.g. the scheduler driver service), run that instead.
set -eu

WORKDIR=/app
cd "$WORKDIR"

# Custom command (compose `command:` / docker run args): run migrations
# first (the scheduler driver needs a current schema), then exec it. This
# lets one image serve multiple roles — web controller and scheduler
# driver — without a second Dockerfile.
if [ "$1" != "frankenphp" ]; then
    echo "Running doctrine migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

    echo "Starting: $*"
    exec "$@"
fi

# FrankenPHP image has no default entrypoint, so $1-frankenphp == default CMD.
# Materialise the Caddyfile the compose scheduler service passes as base64.
if [ -n "${CADDYFILE_BASE64:-}" ]; then
    echo "$CADDYFILE_BASE64" | base64 -d > /tmp/Caddyfile
    CADDY_CONFIG=/tmp/Caddyfile
else
    # Local runs: use the repo copy of the Caddyfile loaded at build time.
    CADDY_CONFIG=/app/docker/Caddyfile
fi

echo "Running doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Sample data is dev/test only; app:seed is a hard no-op with a warning
# when APP_ENV=prod (guard lives in SeedCommand, so even a direct call
# cannot seed a prod database).
echo "Seeding sample data (idempotent, dev/test only)..."
php bin/console app:seed

echo "Warming prod cache..."
php bin/console cache:warmup || true

echo "Starting FrankenPHP..."
exec frankenphp run --config "$CADDY_CONFIG"
