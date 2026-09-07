#!/bin/sh
# Controller container entrypoint: migrate then serve.
set -e

echo "Running doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Sample data is dev/test only; app:seed is a hard no-op with a warning
# when APP_ENV=prod (guard lives in SeedCommand, so even a direct call
# cannot seed a prod database).
echo "Seeding sample data (idempotent, dev/test only)..."
php bin/console app:seed

echo "Warming prod cache..."
php bin/console cache:warmup || true

echo "Starting Apache..."
exec apache2-foreground