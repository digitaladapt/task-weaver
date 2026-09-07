#!/bin/sh
# Controller container entrypoint: migrate then serve — or, when a custom
# command is given (e.g. the scheduler driver service), run that instead.
set -e

# Custom command (compose `command:` / docker run args): run migrations
# first (the scheduler driver needs a current schema), then exec it. This
# lets one image serve multiple roles — web controller and scheduler
# driver — without a second Dockerfile.
if [ "$#" -gt 0 ]; then
    echo "Running doctrine migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

    echo "Starting: $*"
    exec "$@"
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

echo "Starting Apache..."
exec apache2-foreground