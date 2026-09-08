# TaskWeaver

PHP-based LLM task manager, scheduler, and **controller** — the planned
replacement for Open WebUI for task-orchestration use cases. Workers are fully
sandboxed; TaskWeaver owns all credentials and proxies every external tool call.

See [`SPEC.md`](SPEC.md) for the full design and [`WORKER.md`](WORKER.md) for
the worker-facing contract.

## Stack

- PHP 8.4 + Symfony 8.x
- Doctrine ORM + DBAL on **SQLite** (`var/data/taskweaver.db`)
- Twig (admin UI); scheduling via a tick command (cron, Compose profile, or systemd timer)
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

Recurring tasks are driven by a scheduler **tick** that runs every minute.
There are three ways to run it — pick one:

**1. Cron (default, one-shot per minute):**

```cron
* * * * * cd /path/to/taskweaver && bin/console app:scheduler:tick
```

**2. Docker Compose (long-running scheduler driver):**

```bash
docker compose --profile scheduler up -d
```

This starts a `scheduler` container running `bin/console app:scheduler:run` —
a daemon that ticks in a loop (default every 60s). No host cron needed.

**3. systemd timer (non-Docker host):**

```ini
# /etc/systemd/system/taskweaver-scheduler.timer
[Timer]
OnBootSec=1min
OnUnitActiveSec=1min

[Install]
WantedBy=timers.target
```
```ini
# /etc/systemd/system/taskweaver-scheduler.service
[Service]
Type=oneshot
WorkingDirectory=/path/to/taskweaver
ExecStart=/path/to/taskweaver/bin/console app:scheduler:tick
```

Then `systemctl enable --now taskweaver-scheduler.timer`.

Each tick (however it is invoked) marks due recurring tasks `ready` (so they
can be claimed) and advances their `next_run_at`. The check is cursor-based
(`next_run_at <= now`), so a delayed tick or brief outage catches up a missed
slot instead of skipping it. One-off tasks (no schedule) don't need the tick —
they're triggered from the admin UI with **Run** (or `bin/console app:task:run
<id>` from the CLI).

The deployment must run the tick (one of the three options above), or
scheduled tasks silently never fire.

## Reference worker

A self-contained worker lives in [`worker/`](worker/) (its own composer
project). It runs **external tools** via TaskWeaver's proxy and **internal
(sandbox-local) tools** itself.

```bash
cd worker
composer install
php bin/worker taskweaver:run \
  --controller http://127.0.0.1:8987 \
  --enrollment-token dev-enrollment-token \
  --llm-url http://llm-host:11434/v1 \
  --llm-model llama3.1 \
  --name dev-worker
```

`--llm-url` is any OpenAI-compatible chat-completions endpoint (Ollama, vLLM,
llama.cpp server). If the provision response carries `llm_auth`, the worker
routes LLM traffic through the controller's `/api/worker/llm` proxy instead
(see WORKER.md §3); `--once` runs a single claim cycle and exits — handy for
testing.

The worker provisions (Tier-0), claims tasks (Tier-1), runs its own LLM loop
against the local model, and forwards external tool calls to TaskWeaver with an
event-scoped key (Tier-2). **Internal tools** (sandbox-local, e.g. `terminal`)
run in the worker and are logged back to the controller via `/tool/internal`.
It **abandons a step on any 401/403 denial** — no retry, no result report.
(Point `--controller` at `http://127.0.0.1:8987` for the local dev server in
the Quick start above, or at your deployed controller otherwise.)

The default descriptor requests the `dev-worker` image variant so the worker
is provisioned with the tags (`terminal`, `echo`, `weather`) needed to claim
the seeded sample tasks out of the box.

The `terminal` internal tool is a **real sandboxed runner**: commands execute
via `proc_open` inside the worker container with a hard timeout and output
cap. It's safe only because the worker container itself is the sandbox —
no internet egress, no secrets, thrown away with the container. See
`worker/src/Tool/TerminalTool.php`.

Worker tests: `cd worker && vendor/bin/phpunit --configuration phpunit.dist.xml`
(51 controller-side + 27 worker-side tests total across both suites).

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

# Tests (51 tests / 163 assertions):
vendor/bin/phpunit --configuration phpunit.dist.xml
```

Tests run under `APP_ENV=test` with the in-memory SQLite from `.env.test`;
CI does `cp .env.test .env` first to pin it for web requests.

## Deployment

**Docker:** a single multi-stage `Dockerfile` builds all three published
variants (controller, worker, scheduler) via `docker-bake.hcl` — see
[`docker-bake.hcl`](docker-bake.hcl). `docker-compose.yml` runs the full
stack locally (controller + hardened worker — no internet egress, LLM on
the private net — plus the optional scheduler profile).

Production is a standard Symfony app: inject the env vars from
[`.env.example`](.env.example) (no dotenv file in prod), run
`composer install --no-dev --optimize-autoloader`, migrate, and serve
`public/` behind the reverse proxy of your choice. SQLite lives at
`DATABASE_URL` and must be on a persistent volume. **Critically, set up one
of the scheduler tick options from the Scheduling section** (cron, the Compose
`scheduler` profile, or a systemd timer) so recurring tasks fire.

## Backups

TaskWeaver stores all state in a single SQLite file at `DATABASE_URL`
(default: `var/data/taskweaver.db`, inside the compose volume). One
scheduled run of [`bin/backup-db.sh`](bin/backup-db.sh) keeps it safe —
the script is WAL-aware, so you can run it while the app is writing.

Cron on the host (daily, keeps the last 14 snapshots under `var/backups`):

    15 3 * * * cd /opt/taskweaver && bin/backup-db.sh

Or with a custom target and retention:

    bin/backup-db.sh /mnt/backup-share          # snapshots in /mnt/backup-share/<UTC-TS>/
    BACKUP_KEEP=30 bin/backup-db.sh              # retain 30 instead of 14

Each snapshot is validated with `PRAGMA quick_check` before the previous
ones are rotated, so a partial write never clobbers the last good copy.

**Restore.** Stop the app, swap the file, restart:

    sqlite3 /path/to/taskweaver.db ".restore 'var/backups/<UTC-TS>/taskweaver.db'"
    # or just:  cp var/backups/<UTC-TS>/taskweaver.db var/data/taskweaver.db

If the DB lives inside a container, run the script against the host-side
volume path (same file).
