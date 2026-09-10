# TaskWeaver Worker — Design

**Status:** implemented (v1). The agent loop, controller contract, LLM
client, and context budget below are all real code under `worker/` (27 unit
tests) and `src/` (51 controller tests). Verified end-to-end against a demo
MCP server and scripted mock LLM (`dev/demo-mcp.php`, `dev/mock-llm.php`):
provision → claim → LLM loop with tool calls through the controller proxy →
multistep envelope into the final step → shape resolution. Docker images are
written (`Dockerfile` multi-stage — controller, worker, targets
via `docker-bake.hcl` — plus `docker-compose.yml`) but
**not yet exercised** — they need a host that can run Docker-in-Docker.
Companion to `../SPEC.md` (the controller).

**Stack:** PHP 8.4 + Symfony — a single stack shared with the controller. The
worker is a thin **Symfony Console application** that runs the LLM agent loop
inside a hardened, sandboxed Docker container. It has no internet egress,
holds no secrets, and forwards every *external* tool call to TaskWeaver.

> **Revision note (alignment pass).** Rewritten to match the settled controller
> SPEC and the decisions below. Changes from the earlier draft:
>
> - **Worker is PHP 8.4 + Symfony, not Python/task-loop.** task-loop stays a
>   *reference for loop mechanics* only.
> - **Pivot rationale:** task-loop's design expects an MCP server that can
>   support code development — the only candidate (open-terminal) isn't up to
>   the task. TaskWeaver removes that need: every external tool, including code
>   execution, is proxied through the controller. The worker only needs a lean
>   loop.
> - Lifecycle now opens with `POST /api/worker/provision` (enrollment token →
>   ephemeral worker key + config). Matching is **server-assigned**; the worker
>   never self-declares capabilities at claim time.
> - The **LLM channel is explicit**: the local LLM on the private Docker
>   network is a trusted channel (direct, no key). For **keyed/external LLMs**
>   the worker's LLM traffic is proxied through the controller's
>   `/api/worker/llm` route — the controller holds the provider key, the worker
>   never does. The controller sits on that same network and has a second NIC
>   for the public admin UI.
> - Stale-step handling is **lazy expiry**: the controller stamps
>   `expires_at` when the step goes `running`; tool calls and late worker
>   responses past the deadline are rejected as unauthorized (401). No
>   keepalive beats, no periodic sweeper in v1.
> - **Concurrency:** multiple tasks, and multiple steps within a task, run at
>   once; **LLM concurrency is the single controlled resource**.
> - System prompt: **static, loaded into memory at boot, overrideable**.
>   Grounding statement: generated **on the fly**.

---

## 0. The two pieces

```
┌──────────────────────┐   REST (provision/claim/events/tool calls/LLM proxy) ┌────────────────────────┐
│  TaskWeaver           │◄───────────────────────────────────────────────────►│  LLM Worker (Docker)   │
│  (controller)         │     sandboxed: no internet, no secrets              │  PHP 8.4 + Symfony      │
│                       │                                                     │  sandboxed · unprivileged│
│  scheduler            │          direct LLM (no key) ───────────────┐       └───────────┬────────────┘
│  tool proxy           │          keyed LLM via /api/worker/llm ──┐  │                   │  OpenAI-compat HTTP
│  credentials/env      │                                           ▼  ▼                   ▼
│  event log            │                            ┌──────────────────────────┐
│  LLM proxy (keyed)    │                            │  local LLM (Ollama /      │
└───────────┬──────────┘    ┌─────────────┐          │  vLLM / llama.cpp …)      │
            │  external     │ External LLM │◄────────│  OR keyed external LLM     │
            │  tools via    │ (OpenAI/...) │  proxy  │  (via controller, holds key)│
┌───────────▼──────────┐    └─────────────┘          └──────────────────────────┘
│ MCP Server(s)        │     worker ─┐   controller ─┐   LLM ─┐
│ (registered)         │             └───────────────┴───────┘
└──────────────────────┘    controller also has a SECOND NIC → public admin UI
```

**Division of labor** (per the controller SPEC):

- **TaskWeaver** owns: tasks/steps + the two flow shapes, the scheduler, the
  **tool proxy** (ToolDefs → MCP servers), **all credentials** (env vars,
  never on workers), the event log / audit trail, and the **event API keys**
  (minted & revoked).
- **Worker** owns: the agent loop for a run. It holds only its own *internal*
  tools (memory, reasoning helpers) and the system prompt / grounding
  assembly. Anything external — including fetching packages or remote data —
  is forwarded to TaskWeaver, which validates it against the current step,
  executes it, records the `ToolCall`, and returns the result.

**Why we pivoted to this shape:** task-loop's design assumes an MCP server
capable of code development (the only such server, open-terminal, isn't up to
the task). TaskWeaver inverts that — the controller owns *all* tool execution,
so the worker is a small, hardened loop and the sandbox stays genuinely dumb.

---

## 1. The contract, TL;DR

1. The worker boots **without credentials**. It is spawned with a one-time
   **enrollment token** (injected at spawn, never baked into the image).
2. It provisions: `POST /api/worker/provision` → the controller issues an
   **ephemeral worker API key**, notes its tools, and returns its **config**
   (LLM endpoint, timeouts, context budget, LLM concurrency limit, system
   prompt override). Both sides share one config source: the controller.
3. It claims by capability: `POST /api/worker/claim`. Matching uses the
   **server-assigned** tags/tools noted at provision time — never anything the
   worker declares at runtime. Only SANDBOX capability tags are matched;
   external-tool tags (weather, echo, …) are proxied by TaskWeaver and never
   required of the worker.
4. It fetches task detail + steps (**with `is_final`**) + per-step allowed
   tool schemas (annotated **internal** vs **external**).
5. **Only two shapes:** a single step, or N parallel steps followed by one
   final step that consumes all their results as one tool-call-results
   envelope. No DAGs.
6. **Internal tools** (sandbox-local: memory, reasoning helpers) run in the
   worker and are logged via `/api/worker/tool/internal`.
7. **External tools are never executed by the worker.** It forwards the call
   with an **event-scoped API key**; TaskWeaver validates it against the
   step's tags, executes via MCP with real credentials, records the `ToolCall`,
   returns the result.
8. **Context is confined per step.** System + grounding + *this step's*
   instructions + *this step's* toolbox. Previous steps' tool calls and
   thought blocks are never replayed to the model — only the structured step
   *result* flows forward via the final-step envelope.
9. When a step finishes, the worker submits its result via `complete`;
   **every event key issued for that step is revoked** — success or failure.
10. **Concurrency is on the worker:** multiple tasks, and multiple steps in a
    task, run at once — bounded by the **LLM-call concurrency limit** issued
    by the controller. TaskWeaver only ever sees per-step events/keys.

---

## 2. Security model

LLM agents are not trusted. Prompt-injected briefs, hallucinated tools, bad
commands — all of it must be contained. **The container is the blast-radius
boundary**, and "no internet, no secrets" is a hard requirement, not a wish.

### Trust boundaries

| Party | Trusted for | Not trusted for |
|---|---|---|
| TaskWeaver (controller) | tool proxying, credentials, event log, key minting/revocation | — |
| Worker | its own sandbox + internal tools, the LLM loop | secrets, internet/egress, external tools, arbitrary host access |
| Local LLM (private network) | model inference only (direct channel, no key) | tools, credentials, secrets routing; it never receives secrets |
| External / keyed LLM | model inference only (proxied channel through controller) | reaching the worker; the provider key stays on the controller |
| MCP servers | the specific tool they expose | seeing worker state, trusting workers directly |

### The network is the enforcement

```
private Docker network (trusted):   worker ─ controller ─ local LLM
public (untrusted):                 controller's admin UI NIC only
no route out of the private network for the worker
```

Even if the model tries to curl an external API, the packet cannot leave.
So "external tool" is a *property of the executor* (TaskWeaver), not a hope
about model behavior. And because TaskWeaver owns all credentials, an
exfiltrated worker container is close to worthless: no secrets, no egress.

### Container hardening (all must hold)

| Property | Setting | Why |
|---|---|---|
| User | non-root (uid 1000) | first line of defense |
| Capabilities | `cap_drop: [ALL]` | no raw sockets / host tricks |
| Seccomp / no-new-privs | on | shrink kernel attack surface |
| Root filesystem | `read_only: true` | container can't rewrite its own image |
| Network | **private Docker network only** — controller + local LLM; **no internet** | enforces the external-tool rule at the network layer, not the prompt layer |
| Secrets | **none present** | credentials live on TaskWeaver, never here |
| Mounts | ephemeral scratch + persisted per-task workspace only | wiped & isolated per run |
| Resources | cpu/memory limits | a runaway agent can't starve the host |
| Privileged | never | obviously |

---

## 3. Credential model

Three layers, all revocable, none baked into the image.

### Tier 0 — Enrollment token (one-time)

- One-time secret injected into the container **at spawn** via env/secret.
  Never baked into the image.
- Authorizes exactly one thing: `POST /api/worker/provision`.
- Burned on first successful use (or revoked by an operator). Realistically we
  run **one worker**, so this is a spawn-time bootstrap, not an onboarding
  pipeline.

### Tier 1 — Worker API key (ephemeral, issued at provision)

- Minted **server-side** by `POST /api/worker/provision` and returned to the
  worker. Stored hashed-ish as `Worker.api_key` on TaskWeaver.
- Bearer auth for: claim, task fetch, event registration, step status, step
  complete, internal-tool logging.
- **Changing worker settings invalidates it** — the worker must re-provision
  (re-enroll with a fresh token or reconnect under the controller's control).
- Revoked/decommissioned in the admin UI.

### Tier 2 — Event API key (per event, per task × step)

- Minted when the worker calls `POST /api/worker/event/{taskId}/{stepId}`;
  returned with the event ID.
- Authorizes exactly one thing: tool calls for *that event*
  (`X-Event-Key` header).
- Resolves `key → Event → Step → allowed tool tags` for validation.
- **Revoked the moment the worker submits the step's result** (success or
  failure) — and also **dead once `now ≥ step.expires_at`** (see §9).

### Why this shape

- **Blast radius:** a leaked event key exposes one event of one step of one
  task. A leaked worker key authorizes API calls only — no egress, no
  credentials.
- **Instant revocation:** submit `complete` (or the step deadline passes)
  and the step's keys are dead; nothing lingers.
- **Auditability:** every data-mutating call trails back to
  event → step → task → worker.
- **Whole-bucket hygiene:** because external tools run on TaskWeaver, no
  credential ever needs to exist inside the container. There is nothing to
  steal.

Storage: opaque random tokens (no JWTs, no embedded claims). Revocation =
invalidate at step completion / deadline. `Worker.last_seen_at` doubles as a
heartbeat for the admin UI.

---

## 4. Tool model — internal vs. external

Toolbox selection is tag-based and deterministic, exactly as the controller
SPEC defines it:

- A step may call external ToolDef `T` iff `T.tags ∩ step.tags ≠ ∅`.
- Each step therefore sees only the 2–3 tools it needs — the "per-step tool
  scoping" that keeps local-model context tiny.
- Internal tools are whatever the worker's sandbox image ships and the
  controller notes at provision time (`Worker.internal_tools`). They're
  available to any step the worker handles; use is at the worker's discretion,
  and every use is logged.

### Who owns what

| | Lives where | Executed by | Recorded as | Credentials |
|---|---|---|---|---|
| **External tools** | TaskWeaver (`ToolDef` → `McpServer`) | TaskWeaver via MCP client | `ToolCall` under the event (proxy) | env vars on TaskWeaver (`McpServer.cred_vars`) |
| **Fetch** (`fetch`) | TaskWeaver (proxied egress) | TaskWeaver — URL + optional sha256, audited with URL + hash | `ToolCall` under the event | TaskWeaver's egress, never the worker's |
| **Internal tools** | worker sandbox image (`Worker.internal_tools`) | the worker, locally | `ToolCall` via `/api/worker/tool/internal` | none — sandbox-local by definition |

`fetch` is the worker's single controlled path to the outside world
(packages, libraries, remote data). It is just another external tool: the
worker forwards the URL + optional sha256 pin, TaskWeaver pulls it (it has
egress), returns the payload, and records URL + resulting hash in the audit
trail. The worker's sandbox never gets a route.

### The registry relationship

The `ToolDef` / `McpServer` model is the mcp-server YAML registry shape
(name, description, `tags:`, schema) plus controller-side routing — carry that
format forward. Everything a worker is ever handed is either:

- an **external** tool schema (from a `ToolDef`) that it *forwards*, or
- an **internal** tool it *executes*.

The worker-facing payload annotates each schema with which case it is, so the
LLM never guesses whether a tool runs locally or goes to TaskWeaver ("this
tool is executed by the controller on your behalf" = don't try raw HTTP; it
can't leave anyway).

---

## 5. Worker lifecycle

```
1. Boot               container starts with NO credentials.
                      Enrollment token injected at spawn (env/secret).
2. Provision          POST /api/worker/provision  (Bearer: enrollment token)
                      → ephemeral Worker API key + noted tools + config
                        (LLM endpoint + model, timeouts, context budget,
                         llm_max_concurrency, system-prompt override)
3. Claim              POST /api/worker/claim  (Bearer: worker key)
                      Server-assigned capability match only.
                      → { task } or { task: null } → sleep + backoff
4. Fetch              GET /api/worker/task/{taskId}
                      → task brief + steps (with is_final) + per-step
                        allowed tool schemas (internal vs external)
5. Run                Multiple tasks and multiple steps per task run at once,
                      bounded by the LLM-call concurrency limit (config).
                      Single shape: run the one step.
                      Multi shape: run all is_final=false steps in parallel,
                      then the is_final=true step on the results envelope.
                      Per step:
                        a. PATCH status → running
                        b. event loop: POST event → event ID + key
                        c. LLM loop (per-step clean context, §7)
                        d. internal tool → run locally → /tool/internal log
                           external tool → /tool POST with X-Event-Key
                        e. POST complete with the step result
                           → revokes ALL event keys for this step
                        f. ANY 401/403 on this step (tool, status, complete)
                           → ABANDON the step now: drop local state, stop
                           the LLM loop, do not report a result; go to claim.
6. Loop               back to claim; on { task: null } sleep + backoff.
```

**Concurrency inside a run:** parallel steps interleave on the worker while
the LLM is the bounded resource — an LLM-call semaphore (default 1) limits
in-flight model requests, and tool I/O from other steps fills the gaps.
TaskWeaver only ever sees per-step events/keys; task- and step-level
parallelism is entirely a worker concern.

---

## 6. Harness internals — the agent loop (PHP 8.4 + Symfony)

The worker is a **Symfony Console application** (`bin/worker run`). It is a
lean reimplementation of the task-loop mechanics — we borrow *ideas* from
task-loop, not its code:

- tag-filtered, deterministic toolbox selection (here: per-step, from fetched
  schemas);
- a deliberately small prompt (system + grounding + step instruction +
  toolbox);
- static context windowing (head + capped tool output + last N exchanges);
- LLM via an OpenAI-compatible HTTP endpoint on the private network
  (**streaming by default** — SSE deltas assembled in `LlmClient` into the
  standard `{content, tool_calls, usage}` shape, tool-call arguments
  concatenated across `delta.tool_calls[index]` frames; `TASKWEAVER_LLM_STREAM=0`
  opts out per worker);
- LLM-call concurrency limiting with interleaving at tool I/O.

### Prompt assembly — static base, live grounding

**System prompt: static, loaded into memory at boot.** It is compiled from the
image's default and may be **overridden** by controller-issued config at
provision time. It never changes during a run.

**Grounding statement: generated on the fly**, per step/event — the clock,
date, weekday, timezone; optionally location/units from config. This is the
"don't say good evening at 8am" content. Nothing else is ambient.

**Per-step build-up:**

```
system    — worker persona + "raw tool data" warning + who the controller is
            (static, in memory since boot)
grounding — current time/date/zone/units  (generated on the fly)
step      — THIS step's instructions + is_final context
toolbox   — only schemas for tools matching THIS step's tags,
            annotated internal vs external
```

### The no-replay rule is the worker's job to honor

Within a single step, the worker keeps that step's own transcript so the model
can chain tool calls. Across steps, a finished step's conversations are *not*
carried into the next step's context. The final step gets only the envelope of
prior-step results (formatted as tool-call results — `tool_name: step/N`,
`result` / `error`, `status`), exactly as the controller SPEC specifies.
Everything full-fidelity lives in TaskWeaver's event log; the model itself
only ever sees the lean flow.

### Context budget

`max-context = request-size + output-buffer-size` — provisioned by the
controller (config), enforced by the worker. The step's request
(grounding + assignment + pruned history + current tool results + tool-call
parse headroom) stays within `request-size`, so the model always has
`output-buffer-size` free to generate its response.

### Checkpoint / crash-resume — v1 stance

Steps are short and context-clean, so **stateless steps** are the default: a
worker that dies mid-step leaves the step stuck in `running`, and the
controller's lazy expiry handles it (see §9). No mid-step resume in v1; the
durable history is TaskWeaver's event + ToolCall records.

---

## 7. Worker API contract (aligned 1:1 to the controller)

### Auth headers

| Header | For | Carries |
|---|---|---|
| `Authorization: Bearer <enrollment_token>` | provision only | Tier-0 one-time token |
| `Authorization: Bearer <worker_api_key>` | claim, task fetch, event, step status, complete, internal-tool log | Tier-1 worker key |
| `X-Event-Key: <event_api_key>` | external tool calls | Tier-2 event key |

### Endpoints (exactly the controller's set)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/worker/provision` | enrollment token | Declare self → ephemeral worker key + noted tools + config |
| POST | `/api/worker/claim` | worker key | Next capable task, or `null`. Server-assigned matching only |
| GET | `/api/worker/task/{taskId}` | worker key | Task + steps + per-step allowed tool schemas |
| POST | `/api/worker/event/{taskId}/{stepId}` | worker key | Register an event → `{ event_id, api_key }` |
| POST | `/api/worker/tool/{taskId}/{eventId}` | **event key** | Forward an external tool call; TaskWeaver validates + executes + records |
| POST | `/api/worker/tool/internal` | worker key | Log a worker-executed internal tool call |
| POST | `/api/worker/llm` | worker key | Proxy an LLM chat-completions payload to the keyed/external LLM; only issued when the provider needs a key |
| PATCH | `/api/worker/step/{taskId}/{stepId}/status` | worker key | `running` / `failed` transitions |
| POST | `/api/worker/step/{taskId}/{stepId}/complete` | worker key | Submit step result; **revokes every event key for that step**; resolves the shape |

> No `release` endpoint and no retries in v1 — failure flows forward. What
> closes a *stale* `running` step is the lazy deadline in §9.

### Payload shapes (illustrative)

**Provision**

```json
// req  (Authorization: Bearer <enrollment_token>)
{}
// 200 → ephemeral credentials + config
{
  "worker_id": "...",
  "api_key": "...",
  "tags": ["terminal"],
  "internal_tools": ["memory.search", "memory.store"],
  "config": {
    // No key configured → direct local LLM (worker talks to llm_url itself)
    "llm_url": "http://llm:8080/v1",      // TASKWEAVER_LLM_URL, controller env var
    "llm_auth": null,
    // Provider key configured (TASKWEAVER_LLM_API_KEY) → proxied channel:
    //   "llm_url": "http://controller:8080/api/worker/llm",
    //   "llm_auth": { "type": "proxy", "provider": "openai" },
    "llm_model": "Qwen3.5-4B",               // TASKWEAVER_LLM_MODEL, controller env var
    "system_prompt_override": null,
    "step_timeout": 600   // TASKWEAVER_STEP_TIMEOUT, controller env var
    "context": { "request_size": 6000, "output_buffer_size": 1500 },
    "llm_max_concurrency": 1
  }
}
```

**Claim**

```json
// req (no body needed — capabilities are server-side)
{}
// 200
{ "task": { "id": "...", "priority": 3 } }     // or { "task": null }
```

**Task fetch (200)** — steps carry `is_final`; each step lists its allowed
tool schemas, annotated internal/external:

```json
{
  "task": { "id": "...", "name": "...", "description": "..." },
  "steps": [
    { "id": "...", "name": "Weather Summary", "tags": ["weather"],
      "is_final": false, "status": "pending",
      "tools": [ { "name": "weather.get", "scope": "external", "schema": {...} } ] },
    { "id": "...", "name": "Agenda", "tags": ["calendar"], "is_final": false,
      "status": "pending",
      "tools": [ { "name": "calendar.list", "scope": "external", "schema": {...} } ] },
    { "id": "...", "name": "Daily Brief", "tags": ["output"], "is_final": true,
      "status": "pending",
      "tools": [ { "name": "notification.send", "scope": "external", "schema": {...} } ] }
  ]
}
```

**Event registration (200):**

```json
{ "event_id": "...", "api_key": "..." }
```

**External tool call:**

```
POST /api/worker/tool/{taskId}/{eventId}      headers: X-Event-Key: <key>
{ "tool": "weather.get", "args": { "location": "London" },
  "idempotency_key": "..." }

200 → { "tool_call_id": "...", "ok": true,  "result": { ... } }
   or { "tool_call_id": "...", "ok": false, "error": "...", "status": "failed" }
```

**Complete (200):**

```json
// for the final step, the result is the aggregated envelope
{ "result": { "summary": "..." }, "status": "completed" }
```

### Error contract

| Code | Meaning |
|---|---|
| 401 | invalid / revoked / **expired** API key (worker, event, or enrollment) |
| 403 | tool not allowed: key unknown/expired, event not in this task, or `tool.tags ∩ step.tags = ∅` |
| 404 | unknown task / step / event |
| 409 | step not currently `running` / already completed |
| 429 | rate-limited (controller-side limit on external tools) |
| 5xx | transient → worker retries with bounded backoff + jitter |

**Workers abandon a step on denial.** A **401** or **403** on any tool call,
status transition, or `complete` for a step means the step is no longer ours
(expired at `step.expires_at`, already completed elsewhere, or the event key
was revoked). The worker must **stop working the step immediately and drop
it** — do not retry, do not loop the LLM on it, do not attempt to report a
result. Just clean up the step's local state and move on to the next claim.
The controller has already resolved the step's fate (deadline or sibling
completion); a zombie retrying can only burn LLM tokens and clutter the event
log with rejected calls. This applies to *any* denial, not just timeouts —
key revoked, step already completed, tool no longer permitted: all mean
"abandon."

### Idempotency

`/tool` calls can have real side effects (e.g. a notification). A retried
timed-out call could otherwise double-send. The worker includes an
`idempotency_key` per tool call and TaskWeaver dedupes on it (keyed by event).

---

## 8. Container runtime & deployment

### Network topology

```
                ┌────────────── public / admin UI ──────────────┐
                │                                              │
   ┌────────────▼──────────┐        private Docker network      │
   │  TaskWeaver (ctrl)    │◄───────────────┬───────────────────┘
   └────────────┬──────────┘                │
                │          ┌────────────────▼───────────────┐
                │          │  LLM Worker (PHP 8.4, sandbox)  │
                │          └────────────────┬───────────────┘
                │                           │ only egress on the private net
                │          ┌────────────────▼───────────────┐
                └─────────►│  local LLM (Ollama/vLLM/…)     │   direct channel (no key)
                           └────────────────────────────────┘

   keyed/external LLM (OpenAI etc.): worker ──► controller /api/worker/llm ──► provider
   (the worker only ever talks to the controller; the controller holds the provider key)
```

- The **worker and the LLM are on the same private Docker network** — that
  private network is the worker's one trusted channel besides TaskWeaver. For
  an **unauthenticated** local LLM that is the whole story: the worker talks
  to it directly.
- For a **keyed/external LLM**, the worker never talks to it. The worker
  sends its chat payloads to the controller's `/api/worker/llm` proxy (its
  worker key authenticates), and the controller forwards to the provider with
  the provider key it holds. The worker still has **no route to the internet**
  and still holds **no secrets**.
- The **controller is also on that private network**, and has a **second
  network interface** exposing only the web admin UI publicly.
- The worker has **no route to the internet**, ever.

### Dockerfile (PHP reference)

```dockerfile
FROM php:8.4-cli-bookworm

# worker loop + runtime deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        tini ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# non-root worker user
RUN groupadd --system --gid 1000 worker \
 && useradd  --system --uid 1000 --gid worker \
             --home-dir /work --shell /usr/sbin/nologin worker

WORKDIR /work
COPY worker/ ./app/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

USER worker
ENTRYPOINT ["tini", "--", "php", "/work/app/bin/worker", "run"]
```

**Image variants = worker capability tags.** All base workers carry
`terminal`; variants add tags (`php`, `node`, …) — the tags a worker claims
are literally what its image ships. A reference **PHP worker** lives in this
repo under `worker/` for local dev sanity without Docker.

### Run profile (compose, illustrative)

```yaml
worker:
  image: taskweaver-worker:terminal
  read_only: true
  cap_drop: [ALL]
  security_opt: [no-new-privileges:true]
  networks: [taskweaver-private]           # controller + local LLM only
  environment:
    - TASKWEAVER_CONTROLLER_URL=http://taskweaver:8080
    - TASKWEAVER_ENROLLMENT_TOKEN=...      # injected per spawn, never in image
  mem_limit: 1g
  cpus: "1.0"
  tmpfs: [/work/tmp]                       # ephemeral scratch only
  volumes: [worker-workspace:/work/workspace] # persisted per-task workspace
```

(The LLM endpoint comes from controller-issued config, not a baked-in secret. When a provider key is configured, `config.llm_url` points at the controller's `/api/worker/llm` proxy instead — the worker's compose file doesn't change, and no secret is added to the worker environment.)

### Spawn strategy

Long-lived polling worker, one at a time in practice. The persisted per-task
workspace lets a multi-claim task work on code across claims (no re-clone);
it is scoped, capacity-limited, and lifecycle-tied to the worker. (One
container-per-task-run remains a stricter-isolation alternative, not the v1
default.)

---

## 9. Failure handling & lazy expiry

- A step that fails is marked `failed` with an `error` event; the workflow
  does **not** abort.
- The failure flows into the final step's input as a `status: "failed"`
  tool-call result (`error` set, no `result`); the final step decides how to
  present it.
- **No retries in v1.** Submit `complete` (or fail) → event keys expire,
  absolutely, success or failure.

### Stale `running` steps — lazy expiry (option 1, v1)

When the worker marks a step `running`, the controller stamps
`step.expires_at = now + step-timeout` (from controller config, an env var,
default 600s). Everything else is **lazy** — no keepalive beats, no periodic
sweeper:

- **The selection query handles expiry.** "Find steps to run" is
  `queued OR (running AND expires_at < now)`. A stale step that surfaces in
  that set is immediately transitioned: mark `failed`, record an
  `error: worker timeout` event (`type = step_failed`).
- **Event keys are gated by the same timestamp.** An event key resolves to
  `Event → Step`; a tool call is authorized only while the step is `running`
  **and** `now < step.expires_at`. Past the deadline the call is rejected as
  **unauthorized (401)** — a zombie worker that finally wakes up and sends a
  tool call or a late `complete` is rejected, so it can't write results or
  trigger side effects after the deadline.
- **Shape resolution still proceeds.** Once the stale step is marked
  `failed`, the flow resolves like any failure: the final step (if any)
  becomes eligible and consumes the failure as a failed tool-call result.
- **The worker abandons on denial.** A worker that gets a **401/403** against
  this step's event key or its status transitions has just discovered the
  deadline passed — it must **stop working the step immediately and drop
  it** (no retry, no LLM loop, no result report). The step's fate is already
  decided server-side; continuing only burns tokens and logs rejected calls.
  See Error contract in §7.

### Option 2 (future) — periodic sweeper

A dedicated periodic event that sleeps until roughly the next expected TTL
expiry (cap ~5 minutes), then proactively fixes status and revokes tokens.
Keeps the selection query simple, moves cleanup to a targeted job. Deferred
— v1 ships the lazy query.

The distinctive property either way: we don't try to keep a hung worker
honest with beats; we simply make its credentials worthless past the
deadline.

---

## 10. Relationship to existing projects

| Piece | Source | Reused as |
|---|---|---|
| Tool registry YAML (name/description/`tags:`/schema) | mcp-server | basis for `ToolDef` / worker-facing tool schemas |
| MCP transport support (openapi + streamable HTTP, no SSE/stdio) | controller SPEC + mcp-server | TaskWeaver's MCP client; worker never talks MCP directly |
| Loop mechanics (toolbox select, prompt, windowing, LLM concurrency) | task-loop | **reference only** — lean reimplementation in PHP/Symfony |
| Hardened non-root Dockerfile + variant stages | mcp-server / task-loop | worker images; variant stage = worker tag (`terminal`, `php`, `node`) |
| Tool proxy, credentials, event/worker entities, claim matching | TaskWeaver `SPEC.md` | controller side; this doc is its worker-facing reflection |

> **Why not reuse task-loop's code:** its core design assumes an MCP server
> that can support code development; the only candidate, open-terminal, isn't
> up to it. TaskWeaver's proxy model makes that unnecessary — the worker is a
> thin loop and stays that way.

---

## 11. Decisions for v1

| # | Question | Decision |
|---|----------|----------|
| 1 | Worker language | **PHP 8.4 + Symfony**, single stack with the controller. task-loop = reference for loop mechanics only. |
| 2 | Bootstrap auth | One-time **enrollment token** injected at spawn → provision. Realistically one worker. |
| 3 | LLM channel | Local LLM on the **private Docker network** is a trusted channel. Controller on same net + second NIC for public admin UI. |
| 4 | Stale `running` step | **Lazy expiry:** controller stamps `expires_at = now + step-timeout` at `running`; selection query handles it (`queued OR running AND expires_at < now`); event keys gated by the same deadline; late responses rejected unauthorized. No keepalive beats. |
| 5 | Concurrency | Multiple tasks and multiple steps per task run concurrently; **LLM-call concurrency limited** (config, default 1). |
| 6 | System prompt | **Static, loaded into memory at boot**, overrideable via controller config. Grounding generated on the fly. |
| 7 | Internal-tool gating | Open set — step `tags` gate external ToolDefs only (matches controller). |
| 8 | Event-key TTL | Revoke on `complete` **and** dead at `step.expires_at`. (No separate sliding TTL in v1.) |
| 9 | `/tool` idempotency | `idempotency_key` per tool call; TaskWeaver dedupes keyed by event. |
| 10 | Tool-call execution | **Sync-only** in v1; async/streaming tool results deferred. **LLM responses stream** (SSE) end-to-end — the provider-to-worker path (and the controller proxy relay) is streamed; tool *execution* remains sync. |
| 11 | Fetch | Proxied `fetch` tool through TaskWeaver; every call audited with URL + hash. |
| 12 | Grounding/system-prompt provenance | System prompt from image default (+ controller override); grounding generated live per step. |
| 13 | Worker on denial | **Abandon the step immediately** on any 401/403 (tool call, status, or `complete`) — no retry, no LLM loop, no result report; drop local state and move to the next claim. Denial = the step's fate is already decided server-side. |

### Known gaps / future work

- **Docker images untested on real Docker** — `Dockerfile` (controller) and
  `Dockerfile` (worker stage) + `docker-compose.yml` are written and
  reviewed but need a host with Docker to build/run and verify the hardened
  network topology (no internet egress for the worker, controller dual-NIC).
- **Real LLM** — the loop is verified against the scripted mock
  (`dev/mock-llm.php`); pointing it at a real OpenAI-compatible endpoint
  (Ollama/vLLM) needs on-hardware verification.
- **async/streaming tools** — revisit when a long-running tool (e.g. a long
  build) needs it. (Streaming *LLM responses* is done — see STREAMING.md.)
- **Sliding event-key TTL** — cheap to add if we ever want belt-and-suspenders
  beyond the hard deadline.
- **One-container-per-task-run** — stricter isolation, slower start; keep in
  pocket.
- **Mid-step crash-resume** — out of scope while steps are short and context
  clean.
