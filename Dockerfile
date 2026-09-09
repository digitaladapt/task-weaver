# syntax=docker/dockerfile:1.7
#
# TaskWeaver — one Dockerfile, three published runtime variants:
#
#   controller  — Symfony admin UI + worker API, served by FrankenPHP in
#                 worker mode (the kernel stays warm across requests).
#                 Also published/referenced as `latest`.
#   worker      — the sandboxed LLM agent loop (CLI, non-root, no HTTP).
#   scheduler   — runs (`bin/console app:scheduler:run`);
#
# Build the whole set from docker-bake.hcl:
#   docker buildx bake            # build all targets (no push)
#   docker buildx bake --push     # build and push all targets
#   docker buildx bake controller # single target
#   docker buildx bake --print    # show what would be built
#
# Runtime role selection is a container command (compose `command:`) — the
# controller entrypoint runs migrations first and then execs whatever it is
# given, which is what lets the controller image double as the scheduler
# without a second Dockerfile.

# ── Stage: base — shared runtime for every variant ────────────────────────
FROM dunglas/frankenphp:php8.4-trixie AS base

# PHP extensions the controller needs (intl, pdo_sqlite), plus the small
# runtime set every variant uses: ca-certificates (TLS for the worker's
# LLM calls), curl (health checks), tini (worker PID 1 / signal handling).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl tini unzip \
    && rm -rf /var/lib/apt/lists/*

# ── Stage: controller-deps — controller composer deps (layer-cached) ──────
FROM base AS controller-deps

# ARGs are per-stage: redeclare so `COPY --from` can reference it.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Manifests first so dependency layers only rebuild when they change.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

# ── Stage: controller-build — full controller app + prod autoloader ───────
FROM controller-deps AS controller-build

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && rm -rf var/cache/* var/log/*

# Attempt a build-time cache warm. The prod boot guard (Kernel::boot)
# rejects APP_SECRET=build-secret — a placeholder — so this ALWAYS fails;
# the warm-up is a no-op that also exercises the autoloader. The real
# warm-up runs at container start with injected secrets (entrypoint).
RUN APP_ENV=prod APP_SECRET=build-secret bin/console cache:warmup || true \
    && rm -rf var/cache/*

# ── Stage: worker-deps — worker composer deps (layer-cached) ──────────────
FROM base AS worker-deps

# ARGs are per-stage: redeclare so `COPY --from` can reference it.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /work

COPY worker/composer.json worker/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

# ── Stage: worker-build — full worker app + prod autoloader ───────────────
FROM worker-deps AS worker-build

COPY worker/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev

# ── Stage: controller — web controller (admin UI + worker API) ────────────
FROM base AS controller

WORKDIR /app
COPY --from=controller-build /app /app
RUN rm -rf var/cache/* var/log/*

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-taskweaver.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/controller-entrypoint.sh /usr/local/bin/controller-entrypoint
RUN chmod +x /usr/local/bin/controller-entrypoint

# FrankenPHP listens on :80; TLS is terminated by the external proxy.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80

EXPOSE 80

ENTRYPOINT ["controller-entrypoint"]

# ── Stage: worker — sandboxed LLM agent loop ──────────────────────────────
FROM base AS worker

# Non-root worker user (uid/gid 1000). Runtime hardening — read-only root
# fs, no capabilities, no-new-privs, private-only network — is enforced by
# the deployment (compose / CI), not baked in.
RUN groupadd --system --gid 1000 worker \
 && useradd  --system --uid 1000 --gid worker \
             --home-dir /work --shell /usr/sbin/nologin worker

WORKDIR /work
COPY --from=worker-build /work /work
RUN mkdir -p /work/tmp /work/workspace \
    && chown -R worker:worker /work

USER worker

ENTRYPOINT ["tini", "--", "php", "/work/bin/worker", "taskweaver:run"]

# ── Stage: scheduler — controller image, role selected at runtime ─────────
FROM controller AS scheduler

ENTRYPOINT ["tini", "--", "php", "/app/bin/console", "app:scheduler:run"]
