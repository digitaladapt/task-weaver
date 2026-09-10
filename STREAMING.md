# Streaming Support — Planning & Implementation Notes

**Date:** 2026-09-10
**Status:** Planning → implementation in progress
**Branch:** `feat/streaming-llm`
**Scope:** End-to-end streaming for every LLM touchpoint: worker → LLM (direct),
worker → controller proxy → LLM, tool calling (streamed tool-call deltas and
streamed tool results), and progress surfacing back to the admin UI.

---

## 1. Why

TaskWeaver is now "properly useful" for batch-style steps: the worker posts a
non-streaming OpenAI-compatible chat request, gets a full JSON response, and
loops on tool calls. That has three real problems:

1. **Latency / perceived progress.** A step can take 30–120s of model time on
   a single turn; the admin UI shows nothing until the step completes, and a
   long-running final step looks like a hang.
2. **Proxy bandwidth & memory.** The controller's `/api/worker/llm` is a
   buffered JSON relay (`JsonResponse` of the whole body). Large responses
   are buffered end-to-end and the worker's `LlmClient` reads the whole body
   into memory.
3. **Tool calls in streaming mode.** OpenAI-compatible providers emit tool
   calls as a stream of `delta.tool_calls[]` fragments (index-preserving
   `function.arguments` chunks). Without streaming support we can't consume
   them incrementally, and we can't relay progress for tool execution.

Goal: **every LLM interaction streams**, and the artifact of a turn (content +
tool calls + usage) is assembled identically at the end, so the step loop
semantics and context budget stay exactly as they are today.

---

## 2. Design decisions

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | **Worker always sends `stream: true`** when calling the LLM (direct or proxy). The `LlmClient` assembles deltas and returns the same shape as today: `{content, tool_calls, usage}`. | One code path; providers that don't support streaming are handled by a fallback (see §4.4). |
| 2 | **Controller proxy is a true SSE relay.** `POST /api/worker/llm` (with `stream: true` in the body) returns `text/event-stream`, forwarding upstream SSE frames verbatim (after injecting the provider key). | No re-encoding; worker and upstream stay in lockstep; works for any OpenAI-compatible provider. |
| 3 | **Controller proxy validates the payload**, caps `max_tokens`, and — critically — **detects `stream` in the request and forwards it**. Non-streaming requests still get the old buffer-and-relay behavior (backwards compatible). | A hard requirement per the memory note: the controller must identify the proxy channel when external (keyed) LLM is configured. |
| 4 | **Streamed tool-call deltas are assembled client-side (worker).** The parser accumulates `delta.tool_calls[index]`, keeps `id`, `type`, `function.name`, `function.arguments` (string-concatenated), and emits a single final `tool_calls` array identical to the non-streaming shape. | Nothing on the controller needs to know about OpenAI delta semantics; the worker already owns the loop. |
| 5 | **Streaming is opt-out per call** via `chat(..., ['stream' => false])` — used by tests and the mock, and kept as a fallback. | Keeps LlmClient tests with `MockHttpClient` simple; real callers default to streaming. |
| 6 | **Worker progress hatching (optional, cheap):** the worker's `RunCommand` reports per-chunk progress to the controller via a new event (see §6). Collected only when a step is mid-flight; never blocks the LLM loop. | Gives the admin UI live visibility; this is the "streaming story" for the dashboard. |
| 7 | **No changes to event-key/abandon semantics.** Streaming adds no new routes; the proxy remains under `/api/worker/llm` and is covered by the same worker-key bearer auth. | Security model unchanged; 401/403 → abandon-on-denial keeps working. |

---

## 3. Current state (verified)

- `worker/src/LlmClient.php` — non-streaming only: builds JSON payload, POSTs,
  `getContent(false)` buffers everything, returns `content/tool_calls/usage`.
  Supports direct (`baseUrl + /chat/completions`) and proxy (`full_endpoint`).
- `src/Controller/LlmProxyController.php` — reads whole body, forwards with
  `json` body, returns `JsonResponse` of the upstream body. No streaming.
- `worker/src/Command/RunCommand.php` — synchronous `$llm->chat()` per round;
  tool loops already handle tool calls from the message.
- `src/Service/TaskWorkflowService.php` + `Event` entity — event log with
  `llm_call` type etc. Worker never logs LLM round metadata today.
- `SPEC.md` §LLM channel: "Non-streaming only in v1. Streaming (SSE) is a v2
  follow-up" — this document implements that follow-up.
- Symfony 8.1 (controller) / 7.4 (worker) `http-client` both ship
  `EventSourceHttpClient` + `Chunk\ServerSentEvent`, and Symfony 8.1
  `http-foundation` ships `EventStreamResponse` + `ServerEvent` — no new
  dependencies needed for SSE.

---

## 4. Implementation plan

### 4.1 Worker: `LlmClient` streaming core

**File:** `worker/src/LlmClient.php`

- Add `chat(array $messages, array $tools = [], array $options = []): array`
  where `$options['stream']` defaults to `true`.
- Build payload with `'stream' => true` (plus `stream_options` if we want
  usage in the final chunk — keep it minimal: rely on the final `data: [DONE]`
  and optional `usage` chunk).
- `postStreaming($payload)`:
  - `$this->http->request('POST', ..., ['json' => $payload, 'buffer' => false])`
  - iterate `$this->http->stream($response)` chunks:
    - `FirstChunk`/`LastChunk` — skip (except capturing final status/headers),
    - `DataChunk` — parse SSE frames. Reuse a small incremental SSE frame
      splitter (split on `\n\n` / `\r\n\r\n`, keep remainder in a buffer).
      For each `data:` line, decode JSON:
      - `choices[0].delta.content` → append
      - `choices[0].delta.tool_calls[]` → merge by `index` (id/type/name on
        first occurrence; `function.arguments` concatenated)
      - `choices[0].finish_reason` → remember
      - `usage` (final chunk, non-stream variants) → remember
      - `data: [DONE]` → break
    - `ErrorChunk` / transport errors → throw `LlmTransientException`
      (retry semantics preserved).
- After the stream ends, assemble:
  `['content' => $full, 'tool_calls' => $toolCalls, 'usage' => $usage]`.
- **Abort safety:** keep `$response->cancel()` on transient failure so the
  server connection closes (no zombie streams).
- **Non-stream fallback:** if the request included `stream: true` but the
  provider responds `400` with a body that clearly says streaming unsupported,
  or if the status is non-2xx while streaming — treat as today: throw
  `LlmTransientException` / `RuntimeException`; the run loop's existing
  retry + error handling applies. (A `--no-stream` escape hatch exists via
  env `TASKWEAVER_LLM_STREAM=0` → `$options['stream'] = false` for
  providers that break on SSE.)

**Tests:** `worker/tests/LlmClientTest.php` — add a streaming case with
`MockResponse` whose body is an **iterable of SSE chunks** (yielded), assert:
- content assembled across chunks,
- tool-call deltas merged (id/name once, arguments concatenated),
- final `[DONE]` stops the parse,
- transient error mid-stream → `LlmTransientException`.

### 4.2 Controller: `LlmProxyController` streaming relay

**File:** `src/Controller/LlmProxyController.php`

- Keep validation (worker key, API key configured, JSON payload, max_tokens cap).
- Detect `$payload['stream'] ?? false`:
  - **true:** forward with `'buffer' => false`, don't call `getContent()`.
    Return `EventStreamResponse` that:
    - requests upstream (with `Accept: text/event-stream`),
    - iterates `$httpClient->stream($response)`, and for each `DataChunk`
      yields the **raw bytes** (SSE frames passed through verbatim), so the
      worker's parser gets exact upstream frames;
    - on non-2xx **before** any chunk: yield an SSE `error` event with the
      upstream status + scrubbed body, then stop;
    - on mid-stream transport error: yield `event: error` then stop.
  - **false:** keep the existing buffered relay (backwards compatible).
- Scrubbing: keep the existing rule — never reflect `Authorization`, don't
  dump provider keys; the raw-bytes passthrough only forwards the upstream
  SSE data (which contains model text + tool deltas, never headers).

**Tests:** `tests/Functional/LlmProxyStreamingTest.php` (WebTestCase, like
`WorkerProvisionLlmProxyTest`) — boot container with a provider key, mock
`HttpClientInterface` in the test container? No — better: test the controller
logic with `MockHttpClient` injected via a custom `services_test.yaml` or by
constructing the controller directly (unit-style with a fake
`HttpClientInterface` that returns a `MockResponse` with iterable SSE body).
Assert:
- `stream: true` payload → response `Content-Type: text/event-stream`,
- body contains the same SSE frames as the upstream mock,
- `stream` omitted → old JSON relay still works,
- `max_tokens` over cap is clamped before forwarding.

### 4.3 Worker: `RunCommand` consumes streaming (no semantic change)

**File:** `worker/src/Command/RunCommand.php`

- `$this->resolveVar($input, 'llm-stream', 'TASKWEAVER_LLM_STREAM', '1')` →
  pass `['stream' => ('1' === $val)]` to `$llm->chat(...)`.
- The returned shape is identical (`content`, `tool_calls`, `usage`), so the
  tool loop, context pruning, envelope building, and completion are untouched.
- Add a per-round progress log: every ~50 chunks (or every N chars), write
  `Step ... streamed X chars (round N)` at a low verbosity — keeps the
  operator informed without spamming.
- (Optional, see §6) fire `llm_stream` events to the controller.

### 4.4 Mock LLM (`dev/mock-llm.php`)

**File:** `dev/mock-llm.php`

- Accept `stream: true` in the chat payload; when set, emit an OpenAI-style
  SSE stream:
  - content-only entry: chunks of `delta.content` (split into a few pieces to
    exercise incremental assembly) then `data: [DONE]`;
  - tool-calls entry: chunks with `delta.tool_calls[0]` where `arguments` is
    delivered in multiple pieces, then `[DONE]`;
  - `usage` in the final chunk (nonstandard but harmless; our parser tolerates
    it).
- Keep non-streaming behavior (existing tests/scripts) when `stream` is absent.
- Add a `stream_script` option to the mock script file so end-to-end dev can
  choose streaming quickly.

---

## 5. Tool calling with streaming

OpenAI-compatible providers **already stream tool calls as deltas** — no
separate protocol. The worker's assembler (§4.1) reconstructs the exact
`tool_calls` array, so `RunCommand`'s existing `foreach ($toolCalls as ...)`
handles internal and external tools unchanged.

Streamed tool **results** (long-running MCP calls) are out of scope for this
pass (matches WORKER.md decision #10: async/streaming tool results deferred);
the controller's tool proxy stays sync. What we DO get today: the worker sees
the model's tool-call intent earlier (no waiting for full JSON), and the
controller proxy streams model text — the biggest user-visible wins.

---

## 6. Admin UI progress (post-implementation nicety)

After the core is green, optionally:

- Add `llm_stream` / `llm_progress` event types (or piggyback `llm_call`
  payload with `streamed_chars`, `round`, `elapsed_ms`).
- Add a tiny SSE endpoint `/api/admin/tasks/{id}/events` (admin-session auth)
  that tails events and pushes them to the browser via `EventSource`.
- `templates/admin/tasks/show.html.twig`: render streaming milestones (e.g.
  "round 2: 3,400 chars") without a full refresh.

Deferred until core streaming is proven end-to-end with the mock.

---

## 7. Testing & verification

1. `worker`: `vendor/bin/phpunit` — updated `LlmClientTest` (streaming
   assemble + merge + abort).
2. `controller`: `vendor/bin/phpunit` — new `LlmProxyStreamingTest`
   (stream relay, buffered fallback, max_tokens clamp).
3. `dev/mock-llm.php` streaming mode run against a worker in `--once` mode:
   `php dev/mock-llm.php` + `bin/worker run --once ...` → step completes and
   output shows streamed progress.
4. `vendor/bin/php-cs-fixer fix --dry-run` on both trees stays clean.
5. Update `SPEC.md` + `WORKER.md` decision tables: replace "non-streaming only
   in v1 / SSE in v2" with the implemented streaming contract.

---

## 8. Acceptance criteria

- [ ] Worker issues `stream: true` by default for direct **and** proxy channels.
- [ ] `LlmClient` returns the existing shape after assembling streamed
      content + tool-call deltas (+ usage when present).
- [ ] `/api/worker/llm` relays SSE when `stream: true`; still buffers for
      non-streaming callers.
- [ ] Streaming works for a tool-calling turn (deltas → tool_call_id args →
      tool result → next round).
- [ ] Transient errors mid-stream are retried; permanent errors are reported
      (existing error contract preserved).
- [ ] Mock LLM supports streaming; end-to-end dev run works.
- [ ] Docs updated (SPEC.md + WORKER.md + this file).
