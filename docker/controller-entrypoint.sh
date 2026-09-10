#!/bin/sh
# Controller container entrypoint: migrate, start scheduler then serve.
set -eu

WORKDIR=/app
cd "$WORKDIR"

echo "Running doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Sample data is dev/test only; app:seed is a hard no-op with a warning
# when APP_ENV=prod (guard lives in SeedCommand, so even a direct call
# cannot seed a prod database).
if [ "$APP_ENV" != "prod" ]; then
    echo "Seeding sample data (idempotent, dev/test only)..."
    php bin/console app:seed
fi

# cache warmup failure should not block us from running
echo "Warming prod cache..."
php bin/console cache:warmup || true

echo "Starting scheduler runner..."
php bin/console app:scheduler:run &

echo "Starting FrankenPHP..."
exec frankenphp run --config "/etc/frankenphp/Caddyfile"
