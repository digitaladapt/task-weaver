# TaskWeaver worker image — the sandboxed LLM loop.
#
# Hardened per WORKER.md §2 (Container hardening):
#   - non-root user (uid/gid 1000)
#   - no capabilities, no-new-privs (enforced at runtime via compose)
#   - no internet egress (network enforced at runtime via compose)
#   - read-only root filesystem (runtime; tmpfs for scratch)
#
# The image carries NO secrets. The enrollment token is injected at spawn.

# Same FrankenPHP base as the controller — keeps the PHP runtime,
# extensions and base image consistent across the whole stack. The worker
# itself runs the `bin/worker` CLI (no HTTP server).
FROM dunglas/frankenphp:1-php8.4

# worker loop + runtime deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        tini ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# non-root worker user
RUN groupadd --system --gid 1000 worker \
 && useradd  --system --uid 1000 --gid worker \
             --home-dir /work --shell /usr/sbin/nologin worker

WORKDIR /work

# Composer from the official image (checksum-pinned copy).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Build context is the worker/ directory itself (compose and the CI
# docker workflows both use context ./worker so .dockerignore keeps the
# image free of local test vendors and nothing else needs to be ignored).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && mkdir -p /work/tmp /work/ws \
    && chown -R worker:worker /work

USER worker

ENTRYPOINT ["tini", "--", "php", "/work/bin/worker", "run"]