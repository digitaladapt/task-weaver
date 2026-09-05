# TaskWeaver

PHP-based LLM task manager, scheduler, and **controller** — the planned
replacement for Open WebUI for task-orchestration use cases. Workers are fully
sandboxed; TaskWeaver owns all credentials and proxies every external tool call.

See [`SPEC.md`](SPEC.md) for the full design and [`WORKER.md`](WORKER.md) for
the worker-facing contract.

## Stack

- PHP 8.4 + Symfony 8.x
- Doctrine ORM + DBAL on **SQLite** (`var/taskweaver.db`)
- Twig (admin UI), Messenger (scheduling)
- PHP MCP client for OpenAPI + streamable HTTP transports only

## Quick start (controller)

```bash
composer install
php bin/console doctrine:migrations:migrate
php bin/console app:seed          # sample tasks/tools for local testing
php -S 127.0.0.1:8000 -t public   # or use FrankenPHP / symfony server
```

Configuration is env-driven. Committed defaults: `.env.dev` (dev) and
`.env.example` (production-ready template — copy and fill in). `.env` is never
committed; create your own local `.env` / `.env.dev.local` for overrides.
Production injects real environment variables; there is no dotenv file there.

## Reference worker

A self-contained worker lives in [`worker/`](worker/) (its own composer
project). v1 scope: **external tools only** — internal `terminal` tools are
deferred until core functionality is proven.

```bash
cd worker
composer install
php bin/worker taskweaver:run \
  --controller http://127.0.0.1:8000 \
  --enrollment-token dev-enrollment-token \
  --name dev-worker
```

The worker provisions (Tier-0), claims tasks (Tier-1), runs its own LLM loop
against the local model, and forwards external tool calls to TaskWeaver with an
event-scoped key (Tier-2). It **abandons a step on any 401/403 denial** — no
retry, no result report.

The default descriptor requests the `dev-worker` image variant so the worker
is provisioned with the tags (`terminal`, `echo`, `weather`) needed to claim
the seeded sample tasks out of the box.

## Worker API (controller side)

| Method | Path | Auth |
|---|---|---|
| POST | `/api/worker/provision` | enrollment token |
| POST | `/api/worker/claim` | worker key |
| GET | `/api/worker/task/{taskId}` | worker key |
| POST | `/api/worker/event/{taskId}/{stepId}` | worker key |
| POST | `/api/worker/tool/{taskId}/{eventId}` | event key |
| POST | `/api/worker/tool/internal` | worker key |
| PATCH | `/api/worker/step/{taskId}/{stepId}/status` | worker key |
| POST | `/api/worker/step/{taskId}/{stepId}/complete` | worker key |

## Development

```bash
php vendor/bin/php-cs-fixer fix    # code style
php bin/console doctrine:schema:validate
```
