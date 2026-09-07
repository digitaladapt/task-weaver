# TaskWeaver

PHP-based LLM task manager, scheduler, and **controller** — the planned
replacement for Open WebUI for task-orchestration use cases. Workers are fully
sandboxed; TaskWeaver owns all credentials and proxies every external tool call.

See [`SPEC.md`](SPEC.md) for the full design and [`WORKER.md`](WORKER.md) for
the worker-facing contract.

## Stack

- PHP 8.4 + Symfony 8.x
- Doctrine ORM + DBAL on **SQLite** (`var/taskweaver.db`)
- Twig (admin UI); scheduling via a cron-driven tick command
- PHP MCP client for OpenAPI + streamable HTTP transports only

## Quick start (controller)

The one-liner for local development is the self-bootstrapping dev script
(installs PHP deps/extensions on a fresh box, uses a **separate** dev SQLite
DB under `var/data/dev.db` so the real DB is never touched):

```bash
bin/dev.sh start --seed    # start on port 8987 and seed sample data
bin/dev.sh status          # check it's running
bin/dev.sh stop            # stop it
```

The dev server binds to `0.0.0.0:8987` (dial-pad mnemonic **W-V-R = 9-8-7** for
weaver). It is exposed via the reverse proxy at `weaver.lyra-dev.devgnome.com`.

To run manually: `composer install`, then
`php bin/console doctrine:migrations:migrate`, `php bin/console app:seed`,
and `php -S 0.0.0.0:8987 -t public`.

Configuration is env-driven. Committed defaults: `.env.dev` (dev) and
`.env.example` (production-ready template — copy and fill in). `.env` is never
committed; create your own local `.env` / `.env.dev.local` for overrides.
`bin/dev.sh start` auto-writes the git-ignored `.env.dev.local` to point at the
separate dev DB. Production injects real environment variables; there is no
dotenv file there.

## Admin UI

The controller ships a full management web UI (Twig):

- `/` — dashboard (counts, statuses, recent tasks/events/workers/servers)
- `/tasks` — task list with one-off **Run**, plus create/edit with a dynamic
  step editor, tag picker, and friendly schedule picker; **Delete** is a soft
  delete (history preserved, task hidden from scheduling/workers)
- `/tools` — MCP server management (create/edit, **Test Connection**, enable,
  delete) with per-server tool tables; **Sync** pulls the current tool
  definitions from OpenAPI / streamable-HTTP MCP and reconciles them
- `/workers` — registered worker containers and their tags

Routes are plain `app_*` HTML routes; the worker-facing API lives under
`/api/worker/*`.

## Scheduling

Recurring tasks are driven by a **cron entry that runs the scheduler tick
every minute** — there is no always-on scheduler process:

```cron
* * * * * cd /path/to/taskweaver && bin/console app:scheduler:tick
```

Each tick marks due recurring tasks `ready` (so they can be claimed) and
advances their `next_run_at`. The check is cursor-based (`next_run_at <= now`),
so a delayed tick or brief outage catches up a missed slot instead of skipping
it. One-off tasks (no schedule) don't need the tick — they're triggered from
the admin UI with **Run**.

The deployment must run this cron entry, or scheduled tasks silently never
fire.

## Reference worker

A self-contained worker lives in [`worker/`](worker/) (its own composer
project). v1 scope: **external tools only** — internal `terminal` tools are
deferred until core functionality is proven.

```bash
cd worker
composer install
php bin/worker taskweaver:run \
  --controller http://127.0.0.1:8987 \
  --enrollment-token dev-enrollment-token \
  --name dev-worker
```

The worker provisions (Tier-0), claims tasks (Tier-1), runs its own LLM loop
against the local model, and forwards external tool calls to TaskWeaver with an
event-scoped key (Tier-2). It **abandons a step on any 401/403 denial** — no
retry, no result report. (Point `--controller` at `http://127.0.0.1:8987` for
the local dev server in the Quick start above, or at your deployed controller
otherwise.)

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
php vendor/bin/php-cs-fixer fix       # code style
php bin/console doctrine:schema:validate
php bin/console lint:twig templates   # template syntax

# Tests (44 tests / 149 assertions) — PHPUnit ships via Symfony's bridge:
vendor/bin/simple-phpunit
```

Note: there is no `vendor/bin/phpunit` — PHPUnit is installed through the
Symfony phpunit-bridge and run as `vendor/bin/simple-phpunit`.

## Deployment

Production is a standard Symfony app: inject the env vars from
[`.env.example`](.env.example) (no dotenv file in prod), run
`composer install --no-dev --optimize-autoloader`, migrate, and serve
`public/` behind the reverse proxy of your choice. SQLite lives at
`DATABASE_URL` and must be on a persistent volume. **Critically, add the
scheduler cron entry from the Scheduling section** so recurring tasks fire.
