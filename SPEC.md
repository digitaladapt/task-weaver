# TaskWeaver — Spec

## Overview

TaskWeaver is a PHP-based LLM task manager, scheduler, and **controller** — and it is also the **tool proxy**: workers are fully sandboxed (no internet, no secrets), so every external tool call is forwarded to TaskWeaver, which validates it against the current step, then executes it via registered MCP servers.

**The worker is in charge of direct communication with the LLM.** TaskWeaver never talks to the model; it defines what each step may see and do, hands the worker a compact, self-contained context bundle, and lets the worker run its own LLM loop against that bundle.

It's meant to replace our current Open WebUI setup for task orchestration use cases, giving us programmatic control over multi-step, parallelizable LLM workflows.

## Goal / Rationale

The whole point of TaskWeaver is to have **maximum flexibility while minimizing the content sent to the LLM**. We want to be able to use a smaller, local model and have it perform well — which only works if the context we hand it stays tiny and focused.

Mechanisms keep the context confined:

1. **Per-step tool scoping.** Each step gets *only* the tools that step needs — nothing more. A morning-agenda task may report a dozen things overall, but each step hands the LLM just 2–3 tools. Junk-free context means a local LLM is faster, and a smaller model can handle it fine because it has less context to get confused with.
2. **No replay of previous-step internals.** In a multi-step process, a previous step's *tool calls are not sent back to the LLM*, and neither are its previous *thought blocks* (reasoning traces). That content isn't useful enough to justify the context cost of sending it back. Each step runs against a clean, focused context; the only thing that flows forward is the structured step *result*. Everything is still recorded in TaskWeaver's event log for humans and auditing — it just doesn't get shipped back to the model.
3. **A grounded, intentional prompt.** Each step's LLM context is a fixed assembly: a grounding statement (date, time, and similar ambient facts so the model doesn't say "good evening" at 8am — but nothing else), the task assignment, a *limited, pruned* turn history, and the current tool results. Nothing accidental sneaks in.
4. **A hard context budget.** The step is provisioned so that **max-context = request-size + output-buffer-size** — the LLM always has room to produce its response without the context crowding out its output.

Everything in this design is in service of that goal: tag-based stepping, the flat two-shape flow, the tool-call-results envelope between steps, and lightweight steps that a local model can chew through.

### Step Context Budget

The worker assembles each step's LLM context; TaskWeaver provisions the budget. The invariant:

**max-context = request-size + output-buffer-size**

The step's request (grounding + assignment + pruned history + current tool results, plus the LLM's own reserved headroom for tool-call parses and framing) must stay within `request-size`, leaving `output-buffer-size` free so the model always has room to generate its response instead of being crowded out or truncated by its own context.

What a step's context is:

- **Grounding statement** — date, time, timezone, and similar ambient facts so the model doesn't say "good evening" at 8am. Nothing more.
- **Task assignment** — the step description / goal.
- **Pruned turn history** — a limited, trimmed history; raw transcripts are not included.
- **Current tool results** — the result envelope for this step (including the distilled prior-step results for the final step).

What a step's context intentionally excludes:

- Tool schemas **are not compressed** by TaskWeaver. Tool definitions are already authored concise on the MCP-server side; we ship them as-is.
- Previous steps' tool calls and thought blocks are not replayed.
- No full OpenAPI documents, no logger dumps, no credentials.

## Design Principles

1. **Context is confined.** Minimal content sent to the LLM is the point. Each step sees only the tools it needs (2–3 for a small local model), and previous steps' tool calls and thought blocks are never replayed back to the model. See Goal / Rationale.
2. **Workers are dumb and sandboxed.** No internet, no secrets, no arbitrary code. A worker's only trusted channels are the private Docker network it shares with the controller and the local LLM — never the public internet.
3. **TaskWeaver owns all tool credentials.** MCP server endpoints/auth/secrets live here, never on workers.
4. **Everything is scoped and logged.** Every tool call and every log line is associated with a step and recorded with a typed event. Tool calls are authorized per-event via an event-specific API key that **expires the moment the worker submits the step's result** (success or failure).
5. **There are only two task shapes.** Either a single step, or N steps where all but the final step run in parallel and the final step consumes their results as a batch of "tool call results". No complex DAGs.
6. **Failure is data, not a dead end.** A failed step produces an error message that flows forward to the final step, which can still complete (e.g. "here's your events and tasks.. not sure about the weather right now").
7. **All tool wrangling happens via tags.** Workers, steps, and tools are all described by tags. Claiming is a tag-matching problem, not a pool problem.
8. **Secrets never cross the worker boundary.** TaskWeaver never sends secrets to the worker — not in responses, not in traces, not in errors. Secrets stay in TaskWeaver's environment and are injected only into outbound MCP requests at call time.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.4 |
| Framework | Symfony (HTTP Kernel, Serializer, Messenger) |
| ORM | Doctrine DBAL + Entities (SQLite) |
| Templating | Twig (admin UI) |
| Scheduling | Cron-driven Symfony Messenger consumer |
| MCP client | PHP MCP client — **OpenAPI (REST) and modern HTTP/streamable MCP transports only. No SSE, no stdio.** |
| API | REST/JSON (worker-facing), HTML/Twig (admin) |
| Auth | Session (admin) + per-worker API key + per-event API key |
| Credentials | **Environment variables for v1** (DB-backed storage deferred to v2) |
| Workers | **Docker containers (PHP 8.4 + Symfony)** — all base workers carry the `terminal` tag; variants add more (e.g. `php`, `node`) |

## Configuration (env-driven)

All tunable settings — timeouts (including the step timeout), context limits, LLM endpoint, and so on — come from **environment variables**. There is no config file and nothing tunable lives in the database.

- **Development:** `symfony/dotenv` (a dev dependency) loads committed defaults from `.env.dev` plus any machine-local `.env.local` / `.env.dev.local` overrides. `.env` is **never committed**.
- **Production:** real environment variables injected by the runtime (container secrets, orchestration). No dotenv file; `.env.example` is the committed production-ready reference template.

> **Reading mechanism:** the controller reads every `TASKWEAVER_*` variable through the Symfony **container's `%env(...)%`** mechanism (declared in `config/services.yaml`), so values are honored whether they come from a dotenv file (dev) or real exported environment variables (prod). `symfony/dotenv` does **not** call `putenv()` by default, so a raw `getenv()` call in application code would *not* see dotenv-loaded values — do not introduce new `getenv()` reads for controller tunables. (MCP credential values are the exception: their names are dynamic per server, so `CredentialResolver` checks both `getenv()` and `$_ENV`/`$_SERVER`.)

| Variable | Default | Meaning |
|----------|---------|---------|
| `TASKWEAVER_STEP_TIMEOUT` | `600` | Seconds a step may stay `running` before it's considered expired. Used to set `Step.expires_at` when the step is marked running; enforced **lazily** — see Stale Step Expiry. |
| `TASKWEAVER_CONTEXT_REQUEST_SIZE` / `TASKWEAVER_CONTEXT_OUTPUT_BUFFER` | `6000` / `1500` | Step context budget (`max-context = request-size + output-buffer-size`). |
| `TASKWEAVER_LLM_MAX_CONCURRENCY` | `1` | Worker's LLM-call concurrency limit, issued to the worker in its config. |
| `TASKWEAVER_LLM_URL` | `http://llm:11434/v1` | Base URL of the LLM. For an **unauthenticated** local endpoint the worker talks to it directly (private Docker network). For an endpoint that needs an API key (external provider or keyed LAN proxy) this is the upstream the **controller** proxies to — the worker instead gets the controller's `/api/worker/llm` URL. Issued via provision `config` (`llm_url`). |
| `TASKWEAVER_LLM_MODEL` | `llama3.1` | Default LLM model issued to workers via the provision `config` (`llm_model`). |
| `TASKWEAVER_LLM_API_KEY` | *(none)* | API key for the LLM provider (e.g. OpenAI/Anthropic key). Held **only by the controller** (read at request time, never persisted, never issued to workers). When set, the worker's LLM traffic is proxied through TaskWeaver. |
| `TASKWEAVER_ENROLLMENT_TOKEN` | `dev-enrollment-token` | One-time token workers present at provision time (Tier-0). |
| `TASKWEAVER_SYSTEM_PROMPT_OVERRIDE` | *(none)* | Optional system-prompt override issued to workers via provision `config` (`system_prompt_override`). |
| `TASKWEAVER_TIMEZONE` | *(system default)* | Deployment-wide timezone used by the scheduler (e.g. `America/New_York`). When unset, falls back to PHP's `date_default_timezone_get()` (the system timezone). v1 is single-tenant — there is no per-task timezone UI control; each task is stamped with this value at creation for future multi-user support. |

### LLM channel: direct vs. proxied

The worker's LLM channel is **controller-directed** — the worker never decides how to reach the model, and never holds a provider secret.

- **Unauthenticated local LLM (default):** provision `config` carries `llm_url` (the private endpoint) and `llm_auth: null`. The worker talks to it **directly** over the private Docker network, exactly as today. Model traffic never touches TaskWeaver.
- **Authenticated / external LLM:** provision `config` carries `llm_url` = the controller's `{origin}/api/worker/llm` endpoint and `llm_auth: { "type": "proxy", "provider": "openai" }` (or `"anthropic"` / `"generic"`). The worker sends its OpenAI-compatible chat completions to TaskWeaver's proxy route with its **worker key** as the bearer token. TaskWeaver validates the key, resolves the provider key from its own environment (`TASKWEAVER_LLM_API_KEY`, or a per-server `cred_var`), injects it into the upstream request, forwards to `TASKWEAVER_LLM_URL`, and relays the response back.
- **Decision is server-side:** whether a worker gets `llm_auth: null` or `llm_auth.type = "proxy"` is decided by the controller at provision time based on whether a provider key is configured. The worker is a dumb pipe either way.
- **Threat model preserved:** the worker never sees the provider key, never needs internet egress, and can't exfiltrate credentials. The controller remains the only component with secrets and egress.

**Proxy mechanics (v1):**

- `POST /api/worker/llm` — body is the OpenAI-compatible chat/completions payload; `Authorization: Bearer <worker_key>`. TaskWeaver validates the key, then forwards to `TASKWEAVER_LLM_URL/chat/completions` with the provider key attached (`Authorization: Bearer <TASKWEAVER_LLM_API_KEY>` for OpenAI-style, or the provider's expected header) and relays the JSON response.
- **Non-streaming only in v1.** Streaming (SSE) is a v2 follow-up; the worker's `LlmClient` already posts non-streaming payloads, so v1 is a straight JSON relay.
- **Request/response scrubbing** applies to the LLM proxy too: provider keys and any echoed auth material are stripped from logged traces (same rule as MCP proxy, §Tool Calls — Scrubbing).
- **Event/worker key expiry** applies: the same `401/403 → abandon-on-denial` rule that governs tool calls covers the LLM proxy route automatically.
- **No circular dependency:** the LLM proxy is orthogonal to step execution. The controller can proxy for a worker even while its step is mid-flight; both channels are authenticated by the same worker/event key.

Security model: the API key moves from "one per local network" to "one per provider, held centrally". The controller is the single place that holds credentials (already true for MCP servers); the LLM proxy extends that same trust boundary to model access. Provider keys are read at request time via `CredentialResolver` semantics (env → `$_ENV`/`$_SERVER`), never stored in the DB, never returned to the worker.

**v2 direction (model types — not in v1 scope):** v1 exposes a single controller-wide provider/model. The planned direction is named **model types** (e.g. `fast`, `coder`, `abliterated`), each mapping to a concrete provider + model + params, with selection controllable at the **task** and **step** levels (a default type per task, overridable per step, resolved server-side at provision/fetch time). This is deliberately deferred; it layers cleanly on the proxy channel without changing the worker contract (the worker still just receives a resolved endpoint + model in its config).

## Architecture

```
┌──────────────┐              ┌──────────────────────────────────────┐        ┌──────────────┐
│ Human Admin   │              │            TaskWeaver                │        │  MCP Server   │
│  (Browser)    │◄────────────►│  Admin UI (Twig)                     │◄──────►│  (OpenAPI /   │
└──────────────┘              │  Scheduler (Messenger)               │        │   HTTP MCP)   │
                              │  Tool Proxy (validates + executes)   │        └──────────────┘
                              │  SQLite (tasks, events, credentials) │
                              └───────────────▲──────────────────────┘
                                              │ REST (claim / events / tool calls)
                                       ┌──────┴──────────┐
                                       │  LLM Worker      │  sandboxed Docker container:
                                       │  (docker: terminal)│  no internet, no secrets
                                       └─────────────────┘
```

- **Worker sandbox** (Docker, PHP 8.4 + Symfony) holds only its own *internal* tools (memory, reasoning helpers, etc.). Base image always has `terminal`; variants layer on extra tags like `php`, `node`. The worker talks to the local LLM directly over the private Docker network; it shares that network with the controller, which also has a second NIC exposing only the web admin UI.
- **Zero egress by default.** Workers have no internet access — no network egress at all, except the tightly-scoped HTTPS channel back to TaskWeaver. To get anything from outside (a package, a library, remote data, or an **external LLM that needs an API key**), the worker must request it *through TaskWeaver* — as a proxied tool call, or via the proxied LLM channel (§ Configuration → LLM channel). Egress is never opened per-worker.
- **The LLM is reachable two ways, both controller-decided.** Unauthenticated local models: worker talks direct over the private network. Keyed/external models: worker talks to TaskWeaver's `/api/worker/llm` proxy, which holds the provider key. **Only the controller ever holds an LLM API key.**
- **TaskWeaver tool proxy** holds all external tool definitions (MCP servers), their env-var credentials, and the tag-based rules for who may call what.
- Anything a worker needs from the outside world must go through TaskWeaver's `/api/worker/tool` endpoint.

## Data Model

```
┌───────────┐       ┌───────────┐       ┌────────────┐
│   Task    │──────▶│   Step    │──────▶│   Event    │──────▶  ToolCall
│           │       │           │       │            │
│ id        │       │ id        │       │ id         │
│ name      │       │ task_id   │       │ step_id    │
│ description│      │ name      │       │ worker_id  │
│ schedule  │       │ tags      │       │ api_key    │   (event-scoped auth,
│ status    │       │ is_final  │       │            │    expires on step result)
│ priority  │       │ status    │       │ type       │
│ next_run  │       │           │       │ payload    │
│           │       │           │       │ timestamp  │
└───────────┘       └───────────┘       └────────────┘
      │
      ▼
┌───────────────┐     ┌──────────────┐     ┌──────────────┐
│    Worker     │     │  McpServer   │     │   ToolDef    │
│ id            │     │  id          │     │  id          │
│ name          │     │  name        │     │  server_id   │
│ api_key       │     │  transport   │     │  name        │
│ tags          │     │  endpoint    │     │  tags        │
│ internal_tools│     │  cred_vars   │     │  schema      │
│               │     │  enabled     │     │              │
└───────────────┘     └──────────────┘     └──────────────┘
```

### Entities

#### `Task`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `name` | string(255) | Human-readable name |
| `description` | text | Task brief / goal for the LLM |
| `schedule` | string/null | Cron expression (`* * * * *`) or null for one-shot |
| `timezone` | string | Stamp of the deployment-wide timezone at creation — see `TASKWEAVER_TIMEZONE`. Kept for future multi-user support; not edited in the UI. |
| `status` | enum | `draft`, `ready`, `running`, `paused`, `completed`, `failed` |
| `priority` | integer | Higher = claimed first (default 0) |
| `created_at` / `updated_at` | datetime | |
| `next_run_at` | datetime/null | Set by scheduler |

#### `Step`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `task_id` | FK → Task | |
| `name` | string(255) | |
| `description` | text | LLM instructions for this step |
| `tags` | JSON array | Tags determining which external tools this step may call |
| `is_final` | boolean | Marks the consuming step. Only required structural flag — see Flow Shapes |
| `status` | enum | `pending`, `running`, `completed`, `failed` |
| `started_at` / `finished_at` | datetime/null | |
| `expires_at` | datetime/null | **Deadline set when the step is marked `running`: `now + step-timeout`** (env var, default 600s). A stale `running` step (`expires_at < now`) is expired lazily on the next lookup — see Stale Step Expiry. Also gates the step's event keys. |

**Flow shapes** (only two are allowed):
- **Single step**: the step is both first and final; runs alone, no inputs from siblings.
- **Multi-step**: all steps with `is_final = false` run in parallel; the single step with `is_final = true` starts only after every other step has finished, and receives their results (formatted as tool-call results — see Final Step Input below).

#### `Event`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `step_id` | FK → Step | Always associated with a step |
| `task_id` | FK → Task | Denormalized for query convenience |
| `worker_id` | FK → Worker | Who generated it |
| `api_key` | string | **Event-scoped API key** returned to the worker; authorizes tool calls for this event only. Valid only while the step is `running` **and** `now < step.expires_at`. **Revoked when the worker submits the step's result (success or failure), and dead at the step deadline** (expired → 401). |
| `type` | string | Discriminator for the kind of event — see Event Logging |
| `payload` | JSON | Free-form event data (structured by worker) |
| `timestamp` | datetime | |

The event API key is created server-side when the worker registers an event. The worker presents it when forwarding tool calls; TaskWeaver resolves the key → event → step → allowed tags, and permits or rejects the call based on that.

#### `ToolCall`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `event_id` | FK → Event | Always a child of an event |
| `tool_name` | string(255) | e.g. `weather.get`, `calendar.list` |
| `request` | JSON | Arguments forwarded by worker |
| `response` | JSON/null | Result returned to worker / propagated onward |
| `error` | text/null | Tool failure message if any |
| `timestamp` | datetime | |

#### `Worker`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `name` | string(255) | |
| `api_key` | string | **Ephemeral worker-level API key**, issued when the worker comes online and declares itself; invalidated when worker settings change. Bearer auth. |
| `tags` | JSON array | Worker capability tags, **assigned by the controller/registry, not self-declared**. All workers have `terminal`; variants add e.g. `php`, `node`. Used for claim matching. |
| `internal_tools` | JSON array | Tool names the worker can execute locally (memory, reasoning, etc.) — noted at provisioning time |
| `config` | JSON | Worker runtime config: timeouts, context limits, LLM endpoint, etc., issued by the controller when the worker comes online |
| `last_seen_at` | datetime/null | Heartbeat for the admin UI |

**Worker lifecycle / provisioning**
- The worker is provisioned by the controller when it comes online and **declares itself**.
- It receives its **ephemeral worker API key**, its noted tools, and any config (timeouts, context limits, whatever a worker might need) at that point.
- Config is specified in exactly **one place: the controller**. Nothing is configured on the worker side.
- Changing worker settings **invalidates the ephemeral worker API key**; the worker must reconnect/re-provision.
- The worker is never hand-configured independently — it is a thin, sandboxed runner.

**Persisted task workspace**
- Each worker has a **persisted workspace** so a multi-claim task can work on code across claims, instead of re-cloning the repo each time. The workspace is scoped to the worker and its task work, with capacity limits and lifecycle tied to the worker.

#### `McpServer`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `name` | string(255) | |
| `transport` | enum | `openapi` \| `http` (streamable HTTP MCP). **No SSE, no stdio.** |
| `endpoint` | string | Base URL (OpenAPI) or `/mcp` streamable endpoint (HTTP MCP) |
| `description` | text/null | Optional admin note |
| `cred_vars` | JSON array | Names of environment variables holding secrets (never the values themselves) |
| `enabled` | boolean | Disabled servers' tools are not offered to steps |

#### `ToolDef`
| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `server_id` | FK → McpServer | |
| `name` | string(255) | e.g. `weather.get` (unique per server) |
| `tags` | JSON array | Tags used to authorize/route (weather, calendar, notification…). **Manual** — seeded once from the spec on first sync, preserved on re-sync. |
| `schema` | JSON | OpenAPI / tool input schema for the worker (so the LLM knows the args) |
| `description` | text/null | Tool description (refreshed on sync) |
| `removed_at` | datetime/null | Set when the tool disappears from the server on re-sync. A removed tool is flagged (not deleted) so tags/history survive; it is no longer offered to steps or callable. Re-appearing on a later sync clears the flag. |

## Tool Management (external tools)

MCP servers and their tool definitions are managed from the admin UI (`/tools`). TaskWeaver discovers a server's current tool surface and reconciles its ToolDef rows on create/update/sync:

- **Discovery** — OpenAPI servers are read from `{endpoint}/openapi.json`; each operation becomes a tool (name = `operationId`, tags = OpenAPI `tags`, schema = params + requestBody). HTTP streamable MCP servers use JSON-RPC `tools/list` (inputSchema; MCP has no native tags).
- **Create** — every tool is created as a ToolDef; tags are seeded from the server's own spec (OpenAPI operation tags).
- **Update / re-sync** — existing ToolDefs get their schema/description refreshed, but **tags are never overwritten**: they are manual. New tools are created (with spec tags); tools that vanished from the server are **flagged removed** (`removed_at`), not deleted. A tool that reappears is restored.
- **Tag gating** — a tool with no tags is effectively disabled (no step can match it). Removed tools and tools on disabled servers are likewise invisible to steps and rejected at call time.
- `ToolSyncService` performs the reconciliation; `OpenApiToolParser` + `HttpMcpClient::listTools` implement discovery per transport.

## Tool Wrangling via Tags

Tag matching is the only mechanism for tool resolution. Three tag-bearing objects:

- **Steps** list the tags of the tools they're allowed to call.
- **ToolDefs** list the tags describing what they do.
- **Workers** list the tags describing the base tools their sandbox image ships with (everything has `terminal`; variants add `php`, `node`, etc.).

A step may call ToolDef `T` iff `T.tags ∩ step.tags ≠ ∅`. A worker may claim a task iff the union of its own tags covers everything the task exercises externally via TaskWeaver *and* internally via its sandbox image. Matching is deterministic and static.

## Worker ↔ TaskWeaver API

### Worker Auth & Provisioning
- **Provisioning**: when a worker comes online it declares itself via `POST /api/worker/provision`. The controller resolves its declared identity against the registry, issues an **ephemeral worker API key**, notes its tools, and returns its **config** (timeouts, context limits, etc.). The controller is the only place config is specified.
- **Task claim** and **event/step updates**: bearer `Authorization: Bearer <worker_api_key>`.
- **Tool calls**: event-scoped key sent as `X-Event-Key: <event_api_key>` (or in the body). This is what lets TaskWeaver verify the event is allowed to call the requested tool.
- Changing worker settings invalidates the ephemeral worker API key (worker must re-provision).

### Endpoints (JSON)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/worker/provision` | Worker comes online and declares itself → controller issues **ephemeral worker API key**, notes tools, returns runtime config (timeouts, context limits, etc.). |
| POST | `/api/worker/claim` | Worker requests the next task it's capable of handling (or `null`). Workers never see the task list. TaskWeaver matches against *server-assigned* tags/tools, not anything the worker claims at runtime. |
| GET | `/api/worker/task/{taskId}` | Fetch task detail + steps + allowed tool schemas for the claiming worker. |
| POST | `/api/worker/event/{taskId}/{stepId}` | Register a new event → returns event ID + **event API key**. |
| POST | `/api/worker/tool/{taskId}/{eventId}` | Worker forwards an LLM tool call. TaskWeaver validates the event is allowed that tool, executes via MCP, records the ToolCall, returns the result. |
| POST | `/api/worker/tool/internal` | Record a tool call handled **internally** by the worker (no TaskWeaver execution, but still logged). |
| PATCH | `/api/worker/step/{taskId}/{stepId}/status` | Update step status (`running` / `failed` …). |
| POST | `/api/worker/step/{taskId}/{stepId}/complete` | Submit the step's result. **Revokes every event key issued for that step** and resolves its dependencies. |

### Tool Call Flow (external tools)

```
Worker LLM emits:  tool: weather.get(location=London)
        │
        ▼
Worker → TaskWeaver  POST /api/worker/tool/{taskId}/{eventId}
           headers:  X-Event-Key: <event_api_key>
           body:     { tool: "weather.get", args: { location: "London" } }
        │
        ▼
TaskWeaver:
  1. Resolve event_api_key → Event → Step. (403 if the key is unknown or expired)
  2. Validate the event belongs to the task & the step is currently running **and not expired** (`now < step.expires_at`; expired → 401).
  3. Validate tool: tool_name must match a ToolDef whose tags intersect the step's tags.
       └─ reject with 403 if not allowed
  4. Look up ToolDef → McpServer → credential env-vars (resolved from TaskWeaver's env).
  5. Execute via OpenAPI or HTTP-streaming MCP client.
  6. Persist ToolCall (request / response / error) under the event.
  7. Return result to worker.
```

- **No SSE / no stdio transports.** Only OpenAPI (REST) and streamable HTTP MCP servers.
- If a step's tags don't include the requested tool, the call is rejected — the worker cannot bypass this because it has no credentials of its own.

### Large Tool Results (middle-pruning)

Tool responses can be long — Python test output, file listings, and log dumps commonly are. Rather than truncating the tail (which can cut off key details and confuse the LLM) or letting context balloon, we **prune from the middle** when a result exceeds the step's size budget:

- Keep the head and tail of the payload.
- **Clearly denote** where content was removed (e.g. `... [middle N chars omitted] ...`) so the LLM knows data is missing and can ask for more.
- The tool response remains **valid JSON** after pruning.
- The full untruncated response is always stored in TaskWeaver's event log for auditing and retrieval.
- Pruning is applied per-tool-result before it's handed to the LLM; it is *not* a general compression of tool specs (those are kept concise on the MCP-server side and shipped as-is).

### Internal Tools

Some tools are executed by the worker itself within its sandbox (memory, local reasoning helpers). The worker:

1. Is noted for these tools at **provisioning time** (server-side) — it does not self-declare capabilities at claim time.
2. Executes them locally in its container.
3. Logs them as ToolCalls via `/api/worker/tool/internal` so the audit trail stays complete.

## Event Logging

Everything is logged, differentiated by the event `type` field:

| `type` | Meaning | Populated by |
|--------|---------|--------------|
| `info` | Generic log line | worker |
| `llm_call` | An LLM inference round (prompt / completion metadata) | worker |
| `tool_requested` | The tool call as emitted by the LLM | worker |
| `tool_finished` | The result returned from the MCP server | TaskWeaver (proxy) |
| `tool_internal` | A worker-local tool call | worker |
| `step_started` / `step_completed` / `step_failed` | Step lifecycle | TaskWeaver + worker |
| `error` | Any error, incl. failed-step messages | both |
| `step_result` | The submitted result of a completed step | TaskWeaver |

Tool-call granularity is captured twice: the LM's request (`tool_requested`) and the MCP response (`tool_finished`) — both linked to the same step so the timeline reads end to end.

## Claiming & Matching (tag-based)

1. Worker provisions (`POST /api/worker/provision`), then claims (`POST /api/worker/claim`); matching uses **server-assigned** tags/internal-tools, not anything the worker claims at runtime.
2. TaskWeaver computes each candidate task's tool demand = union of step tags, mapped to ToolDefs it can proxy. The worker must be able to cover the task: external tools via TaskWeaver's proxy (TaskWeaver always holds these) and internal tools via its noted set.
3. TaskWeaver picks the highest-priority `ready` task it can give a capable match for (next in line — no pools, no task lists to workers).
4. Returns task + steps + the flattened allowed tool schemas (from ToolDefs whose tags intersect step tags) so the LLM knows what it may call. **Schemas are not compressed/condensed by TaskWeaver** — concise tool definitions are already authored on the MCP-server side.
5. Task transitions `ready → running`.

If no task matches the worker's capabilities, respond `{ task: null }` and the worker sleeps and retries after a backoff.

## Final Step Input

The final step receives the results of all prior steps formatted **exactly like tool-call results** — as far as the final step's LLM is concerned, each previous step was a tool call it made in one prior multi-tool response:

```json
{
  "tool_calls": [
    {
      "tool_name": "step/1",            // or the step's name
      "arguments": { "step": 1, "name": "Weather Summary" },
      "result": { "summary": "Sunny in London, 21°C..." },
      "status": "completed"
    },
    {
      "tool_name": "step/2",
      "arguments": { "step": 2, "name": "Calendar Summary" },
      "result": { "summary": "3 meetings today..." },
      "status": "completed"
    },
    {
      "tool_name": "step/3",
      "arguments": { "step": 3, "name": "Traffic Probe" },
      "error": "weather tool unavailable: upstream 503",
      "status": "failed"
    }
  ]
}
```

The final step consumes this envelope in one shot, exactly as if it had issued those tool calls itself. Failed steps appear as entries with an `error` and `status: failed`; the final step decides how to present that (e.g. "here's your events and tasks.. not sure about the weather right now").

## Failure Semantics (v1)

- A step that fails is marked `failed`; its failure is recorded as an `error` event with a message.
- Neither the task nor the workflow aborts.
- Failed steps are represented inside the final step's input as failed tool-call results.
- The final step still runs, receives everything, and decides how to present failure.
- **Retries are explicitly out of scope for v1** — deferred to the next version.
- Submitting a step's result (via `complete`) expiring its event keys means failed steps' keys die too, success or failure.

## Stale Step Expiry (v1 — lazy)

When the worker marks a step `running`, the controller stamps `expires_at = now + step-timeout` (env var `TASKWEAVER_STEP_TIMEOUT`). No background job, no keepalive beats — **option 1: dead simple, lazy cleanup**. Stale `running` steps are fixed *on the way to doing other work*:

- **The step-selection query handles expiry.** Instead of a separate sweeper, "find steps to run" becomes:
  `queued OR (running AND expires_at < now)`
  A stale step that comes back in that set is immediately transitioned: mark `failed`, write an `error: worker timeout` event (`type = step_failed`).
- **Event keys are gated by the same timestamp.** An event key resolves to `Event → Step`; a tool call is only authorized while `now < step.expires_at` and the step is `running`. Past the deadline the call is rejected as **unauthorized (401)** — so a zombie worker that finally wakes up and posts its results or a late `complete` is rejected, and it can't trigger side effects or write results after the deadline.
- **Shape resolution still proceeds.** Once the stale step is marked `failed`, the flow resolves exactly like any failure: the final step (if any) becomes eligible and consumes the failure as a failed tool-call result.
- **The worker abandons on denial.** A worker that gets a **401/403** against this step's event key or status transitions has just discovered the deadline passed (or the step completed elsewhere) — it must **stop working the step immediately and drop it**. No retry, no LLM loop, no result report. The step's fate is already decided server-side; the worker just cleans up its local state and moves on to the next claim. Span of denial: any rejected tool call, status transition, or `complete` — not just timeouts.
- **Option 2 (future):** a periodic sweeper event that sleeps until the next expected TTL expiry (max 5 min) and proactively fixes status + revokes tokens. This keeps the selection query simple and moves the cleanup to a targeted job. Deferred to the next version — for now the lazy query does the work.

## Scheduling

- Cron expression in `Task.schedule` (via `dragonmantank/cron-expression`), timezone-aware.
- A cron entry runs the scheduler tick **every minute** — it marks due recurring tasks `ready` (enqueue for claim) and advances `next_run_at`:
  ```cron
  * * * * * cd /path/to/taskweaver && bin/console app:scheduler:tick
  ```
- **Save-time cursor:** enabling a schedule in the task editor computes `next_run_at` immediately (`SchedulerService::nextRunAt`), so the admin UI shows a concrete "Next Run" the moment you save — it doesn't wait for the next cron tick.
- **Outage / delayed tick catch-up:** the due check is cursor-based (`next_run_at <= now`), not exact-minute cron matching. If the tick is late (brief outage at 08:00, tick runs 08:14), a slot that never ran is still fired rather than skipped to the next occurrence.
- **Recurring tasks stay scheduled across runs:** completing or failing the final step of a recurring task advances `next_run_at` to the next occurrence (it stays on schedule and is picked up again); only a one-shot task (`schedule = null`) clears the cursor on completion.
- **Fresh run each occurrence:** when the tick promotes a recurring task back to `ready`, its steps are reset to `pending` (started/finished/expiry timestamps and results cleared), so a task that previously completed or failed runs again from step one rather than being stuck on already-terminal steps.
- One-shot tasks (`schedule = null`) stay `draft` until triggered via `/tasks/{id}/run`.

## Credentials (v1)

- MCP server secrets live in **environment variables** on the TaskWeaver host/container.
- `McpServer.cred_vars` records *which* env var names map to the server's auth (headers/tokens), never the values.
- At call time, TaskWeaver reads the env var, injects it into the outbound MCP request, and returns only the tool result to the worker.
- **LLM provider keys** (e.g. `TASKWEAVER_LLM_API_KEY`) follow the same rule: held only by the controller, read at request time, injected into the outbound LLM request, never persisted and never issued to workers (see § LLM channel).
- DB-backed credential storage is deferred to v2.

### Secret containment

- **Secrets never cross the worker boundary.** TaskWeaver does not send secrets to the worker — not in tool responses, not in traces, not in error messages.
- Before a response or error is logged or forwarded, TaskWeaver **scrubs auth material** (headers, tokens, credentials) from request/response traces and from error bodies — upstream MCP servers can reflect headers back in error bodies, so this scrub happens on every outbound path.
- The tool result delivered to the worker is the tool's *data*, never the credentials that fetched it.
- This is enforced centrally in the proxy, not by worker discipline.

## Sandboxing & Isolation

- Workers are Docker containers with **no internet** — zero egress by default; the only external channel is the scoped HTTPS connection back to TaskWeaver.
- **Hardened container profile**: `--network=none` (or explicit network deny), read-only rootfs, drop capabilities (only what's actually needed restored), default-or-stricter seccomp, no host mounts / no host PID / no host IPC, tmpfs for `/tmp`, and CPU / memory / disk limits.
- **Ephemeral per-task workspace is persisted** for multi-claim work (code across claims, no re-clone), but is scoped, capacity-limited, and lifecycle-tied to the worker; it is not a general network egress path.
- Workers hold no secrets; the only interesting credential is their short-lived worker API key (and per-event keys), both revocable.
- Because there's nothing of value inside a worker even when fully compromised mid-task, the blast radius is bounded to the task it's currently working on.

## Package / Artifact Fetch

Since workers have zero egress, anything they need from outside (packages, libraries, remote data) comes through TaskWeaver as a **proxied tool call** — `fetch`. v1 semantics:

- **Fetch anything.** The tool takes a URL (and optional sha256 for verification) and returns the fetched content/artifact to the worker.
- **Every fetch is audited.** Each call records the **URL and the resulting hash** (plus timestamp, worker, task, step, event) in the audit trail. The hash is the fingerprint of exactly what was pulled, so any later discrepancy (mismatched hash, changed artifact, re-pulled content) is visible.
- The fetch runs from TaskWeaver's side (which has egress), *not* the worker's sandbox. The worker only ever sees the payload.
- Optional sha256 pinning lets a step declare "I expect this exact artifact" — a hash mismatch is surfaced rather than silently accepted.

This keeps the "no internet in the sandbox" property intact while still giving workers a controlled, fully-logged path to external artifacts.

## Admin UI (Twig)

| Screen | Contents |
|--------|----------|
| `/tasks` | List: name, status badge, schedule, next run, priority, last run; **New** button to the right of the title |
| `/tasks/{id}` | Overview; step graph (all non-final steps parallel → final step); typed event timeline per step; tool call listing; **Edit** button top right across from "All tasks" |
| `/tasks/new` | Task form: name/description/schedule; step editor with tags per step and a "final step" toggle |
| `/tasks/{id}/edit` | Same form, pre-filled |
| `/tools` | MCP servers + ToolDefs management: server list with live/removed tool counts; create/edit server (name, transport, endpoint, description, cred-vars, enabled); per-server tool table with inline tag editing, live/removed badges, and **Sync now**; delete server |
| `/workers` | Registered workers, tags, internal tools, last seen, connection status |

The step editor enforces the two valid shapes (single step, or all-parallel + one final) so invalid configurations can't be saved.

**Task management (create / edit / soft-delete):**

- **New** (top-right of the Tasks list) opens `/tasks/new`; the top-left **Cancel** returns to the list, top-right **Save** persists.
- **Edit** (top-right of the task view) opens `/tasks/{id}/edit` with the same layout.
- The **step editor** keeps the final step fixed at the bottom. **Add Step** inserts a new row directly above it; every step except the final one shows a small red ✕ to remove it. Non-final steps run in parallel; the final step consumes their results.
- Tag selection is a text box with a **focus dropdown** of all known tags (from ToolDefs, workers, and existing steps) not already selected; choosing one adds it as a bubble matching the view's tag aesthetic. Tags can also be typed and Enter/`,`-separated.
- Removing a step from the editor only takes effect if its **started_at is null** (never started). A step that already ran is kept for history even if dropped from the editor.
- **Delete Task** lives at the very bottom (edit page only), asks for confirmation, and performs a **soft delete**: `task.deleted_at` is set, `next_run_at` cleared, the task is hidden from the admin UI and excluded from scheduling/claiming/running and all worker APIs, but the task, its steps, and its events are preserved for history. `Task::softDelete()` / `Task::restore()`.

**Soft-delete filtering:** `deleted_at IS NULL` is enforced in `TaskRepository` (list/count/recent/`findDue`), `StepRepository::findClaimable` / `findStaleRunning`, the scheduler's due-query, the dashboard status counts/totals, and every worker-facing controller (fetch/status/event/complete/tool). Soft-deleted tasks 404 in the admin UI and are rejected from all worker endpoints.

## Development Notes

- DB: SQLite at `var/taskweaver.db`. No external DB.
- MCP client library for PHP; support only `openapi` and `http` (streamable) transports.
- Credentials via env vars only in v1 (`cred_vars` maps env names, never stores values).
- FrankenPHP available in container; prefer it over PHP-FPM locally.
- Worker side is decoupled — REST + JSON contract only. Workers are Docker images (PHP 8.4 + Symfony, same stack as the controller); base image ships `terminal` capability; variant images add extra tags (`php`, `node`, …). A reference worker lives in this repo under `worker/` for local dev sanity without Docker.
- The worker's system prompt is **static, loaded into memory at boot**, overrideable via controller config; the grounding statement (clock/date/zone/units) is generated **on the fly** per step.

## Decisions Log (v1 — settled)

| # | Question | Decision |
|---|----------|----------|
| 1 | Event key lifecycle | Expires when the worker submits the step's result — success or failure. |
| 2 | What gets logged | Everything, differentiated by event `type` (`llm_call`, `tool_requested`, `tool_finished`, `tool_internal`, `error`, step lifecycle, …). |
| 3 | Worker matching | Tags only. Workers are Docker images; all carry `terminal`, variants add more (`php`, `node`). Capabilities are **server-assigned** (not self-declared). |
| 4 | Final-step input format | Tool-call-results envelope: each prior step is a completed/failed tool call in one batch. |
| 5 | Credential storage | Environment variables for v1; DB-backed deferred to v2. |
| 6 | Worker provisioning & config | Provisioned by the controller when it comes online: gets ephemeral worker API key, noted tools, and controller-issued config (timeouts, context limits). Controller is the only config source; changing settings invalidates the worker key. |
| 7 | Egress policy | **Zero egress by default.** Anything from outside (packages, libraries, remote data) must come through TaskWeaver as a proxied tool call. No per-worker network opening. |
| 8 | Secrets to workers | **Never.** Secrets live in TaskWeaver's env, injected only into outbound MCP requests, scrubbed from traces/errors, never returned to the worker. |
| 9 | Tool response truncation | Never truncate from the tail (loses key details). For oversized giant-string results (e.g. Python test output): **prune from the middle**, clearly denote the removal, keep the response valid JSON; register full copy in event log. |
| 10 | Tool spec compression | **None by TaskWeaver.** Tool definitions are authored concise on the MCP-server side and shipped as-is. |
| 11 | Worker/LLM split | TaskWeaver is the controller; the worker handles direct communication with the LLM. Context budget: max-context = request-size + output-buffer-size. |
| 12 | Workspace | Persisted per-task workspace so a task can work on code across claims (no re-clone). Scoped, capacity-limited, tied to the worker. |
| 13 | Fetch semantics | `fetch` is **fetch-anything** (URL + optional sha256) proxied by TaskWeaver; **every call audited with URL + resulting hash**. |
| 14 | Config source | **Environment variables** everywhere. `symfony/dotenv` is a **dev-only dependency**; production uses real env vars. |
| 15 | Stale step expiry | **Lazy (option 1):** `Step.expires_at = now + step-timeout` at running; the selection query handles expiry (`queued OR running AND expires_at < now`); event keys gated by the same deadline. Periodic sweeper (option 2) deferred to next version. |
| 16 | Worker on denial | A worker that gets a **401/403** on this step (tool call, status, or `complete`) **abandons the step immediately** — no retry, no LLM loop, no result report; it drops local state and moves to the next claim. Denial = the step's fate is already decided server-side. |
| 17 | LLM auth / external LLMs | **Controller-mediated proxy.** When a provider key is configured, the worker's LLM traffic is proxied through TaskWeaver's `/api/worker/llm`; the worker never holds the key and never needs egress. Unauthenticated local LLMs stay direct. v1 is non-streaming (SSE in v2). |
| 18 | LLM model selection | v1 = **one controller-wide provider/model**. Named **model types** (`fast`/`coder`/`abliterated`/…) selectable at task/step level is a **v2** direction (see § LLM channel). |

## v2 Roadmap (intent, not scope)

- **LLM model types** — named types (`fast`, `coder`, `abliterated`, …) → concrete provider/model/params; selectable at task and step level; resolved server-side. Deferred so v1 stays a single, clean provider channel.
- **Streaming LLM proxy** (SSE) through `/api/worker/llm`.
- DB-backed credential storage replacing v1's env-var-only approach.
- Periodic stale-step sweeper (vs. v1's lazy expiry).
