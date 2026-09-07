#!/bin/sh
# Controller container entrypoint: migrate then serve.
set -e

echo "Running doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Seeding sample data (idempotent)..."
php bin/console app:seed || echo "seed skipped (already seeded?)"

echo "Warming prod cache..."
php bin/console cache:warmup || true

echo "Starting Apache..."
exec apache2-foreground