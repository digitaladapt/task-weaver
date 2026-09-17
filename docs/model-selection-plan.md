# Model Selection — Design Plan

Status: **draft** (branch `feat/model-selection`)
Scope: choose the LLM model per task-step and per conversation-message, with the controller's env-configured model (`TASKWEAVER_LLM_MODEL`) as the deployment default; expose the llama.cpp server's model list through a broadly-compatible endpoint.

---

## 1. Summary

Today the model is a single deployment-wide string. It is set in env
(`TASKWEAVER_LLM_URL` / `TASKWEAVER_LLM_MODEL`), flows into `ProvisionService`,
which issues `llm_model` to a worker **once at provision time**, and the worker
bakes it into a single `LlmClient` for its lifetime (`RunCommand` ~line 144).

We are adding **per-step and per-message model overrides**:

- **`step.model` / `message.model`** (nullable string, new columns): optional
  model override for exactly that unit of work. `null` → default.
- **Default = env** (`TASKWEAVER_LLM_MODEL`), exactly as today — the controller
  resolves "no override" to that value before instructing the worker.
- **Model discovery endpoint**: the controller proxies the LLM's model list.
  llama.cpp's `llama-server` (our compose `llm` service) implements the
  **OpenAI-compatible `GET /v1/models`** listing, so we expose it at
  `/api/worker/llm/models` (Tier-1 worker) and `/api/models` (admin session):
  - the UI gets a `<datalist>` of valid model names with no new format;
  - the controller stays in the middle — same trust boundary as the existing
    `/api/worker/llm` chat proxy (workers never hold the provider URL or key);
  - because it is the OpenAI `/v1/models` shape, it also works against any
    OpenAI-compatible upstream in proxy mode (vLLM, LM Studio, …).

Resolution precedence (most-specific wins):

```
step.model / message.model      (stored override, when non-null)
        ↓ else
TASKWEAVER_LLM_MODEL            (deployment default, from env)
        ↓ else
hardcoded 'Qwen3.5-4B'          (legacy fallback, same as today)
```

**Why on the step/message and not the task?** A task is a container of steps;
work happens at step level and the worker executes steps — the claim/fetch API
is already step-scoped (`claim` returns `{task, step}`; `fetchTask` returns
per-step entries with tags/description). Conversation messages are the
step-equivalent for chat replies (transient reply tasks copy message → step;
conversations-plan.md D6/D7). Putting the override on the unit of work keeps
the API shape unchanged, mirroring how `tags` work today.

**Why no model-registry table / FK?** The llama.cpp server's model list is
*runtime truth* (models change with `models.ini` restarts), not durable data —
a DB registry would drift from the actual server state. We store the override
as a plain nullable string and validate softly against the live list (§7).

---

## 2. Locked decisions

| # | Decision |
|---|----------|
| M1 | Overrides live on **`step` and `message`** (nullable string), not on task or conversation. Task/conversation-wide defaults come later if needed. |
| M2 | Default model comes from env (`TASKWEAVER_LLM_MODEL`) — the controller resolves a null override to the env default before issuing to the worker. |
| M2a | **Worker model precedence** (resolved Q2): explicit `--llm-model` (CLI) or `TASKWEAVER_LLM_MODEL` (env) — the *worker override* — always wins; then the task-step's claim-issued model; then the default model given at initialization (provision config, only used when the controller doesn't send one, i.e. mixed versions). |
| M2b | **Proxy is model-agnostic** (resolved Q1): `LlmProxyController` forwards the worker's chat payload `model` field **unchanged**. The proxy's only purpose is key custody and enabling access — it never imposes model policy. The claim payload carries the desired model regardless of whether the LLM is proxied or direct. |
| M3 | Model discovery is a **controller-proxied OpenAI-style `GET /v1/models`**: `GET /api/models` (admin session) + `GET /api/worker/llm/models` (Tier-1 worker key) — one `ModelCatalogService`, two routes. |
| M3a | Upstream call: `GET {llm_url}/models`, controller-initiated (direct mode: straight to the LLM; proxy mode: the same upstream the chat proxy uses, with the provider key attached). |
| M3b | The normalized list is cached in-process for `TASKWEAVER_LLM_MODELS_CACHE_TTL` seconds (default 300; `0` disables) so page renders don't hammer the LLM server. Admin route accepts `?refresh=1` to bust it. |
| M3c | Fallback when the upstream list is unavailable/empty: endpoints return `{models: [default], default}` — the UI datalist degrades gracefully, no exception. |
| M4 | `claim` response gains `step.model` — the **resolved** model (override if set, else env default), always a string. `fetchTask` step entries gain `model` — the **raw stored** value (nullable) for fidelity/audit. |
| M5 | The claim-issued model is the per-claim instruction the worker applies for that step. It is transient (never persisted back onto the step). |
| M5a | Model choice does not affect claim matching — any worker whose capability tags cover the step can claim it, regardless of model (a llama.cpp server can load several models via `models.ini`; any worker can serve any of them). |
| M5b | The worker logs the effective model per step in the `step_started` event payload (`model` key), so event history shows which model actually ran. |
| M5c | Transient reply tasks (conversations) propagate `message.model` into the throwaway step, and the assistant message records the model actually used (same `message.model` column serves user-requested override and assistant provenance). |
| M5d | System prompt, context budget, max tokens, and concurrency stay deployment-wide in v1 — not model-scoped. |
| M6 | Validation is **soft**: unknown model names are allowed with a warning, never blocked (the operator may point `TASKWEAVER_LLM_URL` at a different server at deploy time). |
| M7 | UI: free-text model input + `<datalist>` suggestions from the live list, on the step editor (`templates/admin/tasks/form.html.twig`) and the conversation composer (`templates/admin/conversations/show.html.twig`) — one native element each, matching the existing tag-picker pattern (native form post, no JS framework). |
| M8 | Env/docs: `TASKWEAVER_LLM_MODELS_CACHE_TTL` added to `.env.dev`, `.env.example`, `config/services.yaml`; `TASKWEAVER_LLM_MODEL` remains THE default knob; `models.ini` remains where additional models are loaded. |

---

## 3. Data model

Two new nullable string columns, one migration
(`Version20260916100000`):

| Table | Column | Type | Notes |
|-------|--------|------|-------|
| `step` | `model` | string(255), NULL | Per-step model override; `NULL` = default. |
| `message` | `model` | string(255), NULL | Per-message model override (and assistant-run provenance, M5c); `NULL` = default. |

- Online-safe `ALTER TABLE … ADD … NULL` — no backfill; existing rows mean "default".
- Entity getters/setters: `Step::getModel()/setModel(?string)`, `Message::getModel()/setModel(?string)`.
- No FK, no index: it is input/display data, never a query filter.

---

## 4. Model discovery (`ModelCatalogService`)

### 4.1 Upstream shape — pinned from the live server

llama.cpp's `llama-server` implements the OpenAI-compatible `GET /v1/models`.
Captured 2026-09-16 from the reference deployment
(`http://zenith.devgnome.com:8080/v1/models`, llama.cpp server with
`--models-preset /config/models.ini`), one entry shown with fields trimmed
for readability:

```json
{
  "object": "list",
  "data": [
    {
      "id": "Qwen3.6-35B-A3B-HauhauCS",
      "aliases": [],
      "tags": [],
      "object": "model",
      "owned_by": "llamacpp",
      "created": 1789565914,
      "status": {
        "value": "unloaded",
        "args": ["/app/llama-server", "--host", "127.0.0.1", "--jinja", "--port", "0", "--alias", "Qwen3.6-35B-A3B-HauhauCS", "--ctx-size", "131072", "--model", "/models/Qwen3.6-35B-A3B-HauhauCS.gguf", "--mmproj", "/models/Qwen3.6-35B-A3B-HauhauCS-mmproj.gguf", "--parallel", "1", "--threads", "6"],
        "preset": "[Qwen3.6-35B-A3B-HauhauCS]\njinja = true\n..."
      },
      "architecture": {
        "input_modalities": ["text", "image"],
        "output_modalities": ["text"]
      },
      "source": "preset",
      "can_remove": false
    }
  ]
}
```

Facts learned from the capture:

- The base shape is the OpenAI listing (`object: "list"`, `data[]` with
  `id`), so normalization stays trivial: read `data[].id` only.
- **Aliases are per-entry arrays** (`aliases: []`), not separate rows.
- Models registered via preset report `status.value: "unloaded"` until first
  use — llama.cpp lazy-loads them on the first request naming that model. So
  an "unloaded" model is still a **valid selectable target**; it is not an
  error state.
- `architecture.input_modalities` can include `"image"` (vision-capable,
  via `--mmproj`) — a future UI hint candidate, not v1 scope.
- `owned_by: "llamacpp"`, `source: "preset"`, `can_remove: false` —
  informational only.

The full captured body is the pinned test fixture for `ModelCatalogService`
(§9); the JSON above is its trimmed rendering.

### 4.2 Normalization contract

```php
ModelCatalogService::list(): array{models: string[], default: ?string}
```

- GET `{llm_url}/models` (controller-initiated; provider key attached in
  proxy mode).
- Extract `id` from every `data[]` entry that has a non-empty string `id`;
  drop duplicates; preserve upstream order; never trust other fields.
- Output `{models: [...], default: TASKWEAVER_LLM_MODEL}`.
- Upstream failure / malformed body / empty list → `{models: [default],
  default}` with no exception (M3c).
- Cache the **normalized** result in-process, TTL from
  `TASKWEAVER_LLM_MODELS_CACHE_TTL` (default 300 s; `0` = always fetch);
  `?refresh=1` on the admin route bypasses the cache.

### 4.3 Routes

| Route | Auth | Purpose |
|-------|------|---------|
| `GET /api/models` | admin session | UI datalist source + operator REST endpoint |
| `GET /api/worker/llm/models` | Tier-1 worker key | Worker-side discovery (parity with the chat proxy) |

Both return the normalized `{models, default}` JSON.

---

## 5. Claim / fetch / worker flow

Current flow, unchanged in shape:

1. `POST /api/worker/claim` → `{task: {id, priority}, step: {id}}`
2. `GET /api/worker/task/{taskId}` → per-step details (tags, description, tools)
3. Worker builds the prompt and runs the tool loop through one `LlmClient`
   holding the provision-time model.

New flow:

1. `claim` response gains `step.model` — the **resolved** model (override if
   set, else env default), always a string, never null (M4).
2. `fetchTask` step entries gain `model` — the **raw stored** value
   (nullable; the resolved value comes from claim).
3. Worker: `RunCommand` currently builds a single `LlmClient` at start-up.
   It instead resolves the model **per claimed step** — claim-issued value
   when no local operator override exists — and applies it to the client for
   that step (add `LlmClient::setModel(string)`; the client is reused, only
   the model field changes).
4. Proxy mode: `LlmProxyController` forwards the worker's chat payload —
   including its `model` field — **unchanged** to the keyed upstream (M2b).
5. Audit: the worker includes `model` in the `step_started` event payload
   (M5b) — event history shows which model actually ran.
6. Conversations: `ConversationService::ensureReplyRun` copies
   `message.model` → throwaway step (M5c); on materialization the assistant
   message records the model that ran (M5c).

### 5.1 Worker-side resolution order (per step) — resolved Q2

```
--llm-model (CLI)                        worker override
        ↓ else
TASKWEAVER_LLM_MODEL (env)               worker override
        ↓ else
claim-issued step model                  task-step selected model
        ↓ else (old controller, no field)
provision config llm_model               default given at initialization
```

The worker override tier (CLI/env) preserves today's operator escape hatch —
a worker started with `--llm-model` keeps it for every step. Below that, the
task-step's selected model (claim-issued, already resolved to
override-or-default by the controller) applies per step; the init-time
provision default is only reached against an old controller that doesn't
send `step.model`.

### 5.2 Wire shapes

Claim response:

```json
{
  "task": { "id": "…", "priority": 3 },
  "step": { "id": "…", "model": "Qwen3.5-4B" }
}
```

`fetchTask` step entry (excerpt):

```json
{ "id": "…", "name": "…", "model": null, "tags": [], "is_final": false }
```

### 5.3 Mixed-version compatibility

- Old worker / new controller: the worker ignores the new `step.model` field — harmless.
- New worker / old controller: no `step.model` in the claim payload → falls back to the provision config → default. No breakage either way.

---

## 6. Admin UI

### 6.1 Step editor (`templates/admin/tasks/form.html.twig`)

- Per step row: free-text input `steps[<i>][model]` + shared
  `<datalist id="step-models">` from `ModelCatalogService`.
- Empty field → `null`. Placeholder shows the resolved default, e.g.
  `Default (Qwen3.5-4B)`.
- Persisted in `TaskController::applyForm` next to `setTags` (`setModel`).
- Small hint: "Model used for this step. Leave empty for the default."

### 6.2 Conversation composer (`templates/admin/conversations/show.html.twig`)

- Model input next to the tags input, own `<datalist>`, same pattern.
- `ConversationController::message` reads the field;
  `ConversationService::postMessage` gains a `?string $model = null`
  parameter.
- Assistant messages render "Model: X" from the provenance column (M5c).

### 6.3 Workers page — optional polish

Show each worker's provisioned `llm_model` from its config snapshot. Not
blocking; decide at implementation time.

### 6.4 Worker-side picker — **No**

The worker is headless; per-step model choice is a controller-side decision
(M5). The worker's job is to honor the claim-issued model and log it (M5b).

---

## 7. Validation & failure modes

| Case | Behavior |
|------|----------|
| Unknown model at form save | Soft warning (M6): after POST, compare against the cached live list; if absent, re-render with a non-blocking warning above the field; the value is kept. |
| Unknown model at runtime | LLM answers 4xx → `LlmClient` permanent error → step fails with the upstream error text surfaced (existing `step_failed` flow, PR #32 UI shows it). |
| Model list upstream unreachable | Endpoints degrade to `{models: [default]}` (M3c); chat calls unaffected. |
| Proxy-mode model mismatch | Not applicable — the proxy forwards `model` unchanged (M2b); model resolution happens entirely on the worker from the claim payload. |
| Migration | Adds nullable columns only; online-safe; existing rows = default. |

---

## 8. Open questions

None — Q1 and Q2 were resolved on 2026-09-16 (see M2b and §5.1):

- **Q1 (resolved)**: the worker gets the desired model on claim regardless
  of channel; the LLM proxy forwards it unchanged — its only job is key
  custody and access.
- **Q2 (resolved)**: worker override (`--llm-model` / `TASKWEAVER_LLM_MODEL`)
  > task-step selected model > default model given at initialization.

---

## 9. Testing & verification

- **Controller functional tests**: claim response carries the resolved
  `step.model` (override set → override; unset → default); `fetchTask` step
  entries carry raw `model`; reply-run propagation (message.model → step;
  assistant provenance); `/api/models` returns the normalized list against a
  mocked upstream; cache TTL behavior.
- **Worker tests**: `LlmClient` sends the per-step model in the payload;
  `RunCommand` resolution order (CLI > env > claim-issued).
- **Migration**: migrate a fresh DB and a fixture DB; existing rows behave as
  default.
- **Live llama.cpp fixture**: captured 2026-09-16 from the reference
  endpoint (`http://zenith.devgnome.com:8080/v1/models`) — see §4.1. Use the
  captured body as the primary `ModelCatalogService` test fixture (add a
  second fixture with a second `data[]` entry to exercise multi-model
  normalization).

## 10. Implementation order

1. Migration + `Step`/`Message` getters/setters.
2. `ModelCatalogService` (fetch/normalize/cache/fallback) + the two routes.
3. `ClaimController` / `TaskFetchController` model fields.
4. Worker: `LlmClient::setModel`, per-step resolution in `RunCommand`,
   `step_started` payload `model` key.
5. Conversations: reply-run propagation + assistant provenance.
6. UI: step editor + composer model fields with datalists; soft-save warning.
7. Tests + live `/v1/models` fixture capture.
8. Docs: `WORKER.md` claim payload, `.env.example`, README env table.