# MCP Progress Channel — Client Support (streamable MCP progress)

**Status:** planning · 2026-09-12
**Issue:** [task-weaver#28 — feat(mcp): add `_meta.progressToken` support to HttpMcpClient](https://code.devgnome.com/public/task-weaver/issues/28)
**Interlocks:** context-loom SPEC §5.2 / §11 gate v3.1 · `STREAMING.md` §5 · `WORKER.md` decision #10

---

## 1. Why

TaskWeaver is context-loom's primary consumer. Context Loom's v1 streaming deliverable is the **progress channel**: long-running tools (`run_backup`, `log_read`, process runs) emit MCP `notifications/progress` frames via `ClientGateway::progress()` — but **only for clients that opt in** by putting `_meta.progressToken` in their `tools/call` request.

Today our client is strictly request/response:

- `HttpMcpClient::call()` sends `{name, arguments}` — no `_meta`, fixed JSON-RPC `id: 1`.
- It buffers the whole response with `getContent(false)`, then walks it for SSE frames *after the fact*.
- So progress frames **never arrive mid-call** — for TaskWeaver, context-loom tools look request/response and its progress channel **silently no-ops** (documented as our caveat in context-loom's gate v3.1).

The user-visible symptom: a 10-minute backup appears **hung** — no output until completion, then one big `CallToolResult`.

**Framing — what this is and is not.** This issue is about the **progress channel** (liveness / observability while a tool runs). It is *not* chunked tool results: true streamable tool results (`StreamableToolResult`) don't exist in `mcp/sdk` 0.8.x and are parked upstream (context-loom D5). The worker will still receive exactly one `CallToolResult` at the end, unchanged. What we get: mid-call visibility for operators (and, once upstream lands, the consumer is already frame-at-a-time capable).

---

## 2. Verified facts (2026-09-12)

All checked against this repo + `context-loom/vendor/mcp/sdk` 0.8.x + Symfony HttpClient 8.1 in this tree.

### Our client (current state)

| Call | Timeout | Payload | Read style |
|---|---|---|---|
| `initialize` | 30s | id=1, protocolVersion `2025-03-26` | buffered |
| `tools/list` | 30s | id=1, `{}` params | buffered |
| `tools/call` | 60s | id=1, `{name, arguments}` | buffered (`getContent(false)`), frames parsed post-hoc |

- `Accept: application/json, text/event-stream` is already sent (required for SSE responses) — good.
- A fresh `initialize` handshake runs per call (`sessionHeaders()`), so each call gets its own `Mcp-Session-Id`.

### mcp/sdk server side (context-loom's vendored SDK)

- **`_meta` position**: `params._meta` — confirmed in `Schema/JsonRpc/Request::fromArray()`: it reads `$data['params']['_meta']` and `ClientGateway::progress()` reads `$meta['progressToken']` from the active request's meta.
- **Frames ride the same POST response**: when a handler emits notifications, `StreamableHttpTransport` turns the response into `text/event-stream` and flushes queued notifications as `event: message\ndata: {...}\n\n` frames — the final JSON-RPC result is flushed the same way when the fiber terminates. No separate GET stream needed for our use.
- **Gating**: no `progressToken` → `ClientGateway::progress()` returns immediately (spec says don't send). With a token → frames flow.
- **Log notifications are deprecated** (SEP-2577) — not our channel, don't touch.
- Progress notification shape (schema + wire): `{jsonrpc, method: "notifications/progress", params: {progressToken, progress, total?, message?}}`.
- The Python `mcp` SDK (`mcp-server`, our other MCP server) has the same `report_progress()` capability — so this support benefits both servers.

### Symfony HttpClient mechanics (empirically validated in this tree)

- **`timeout` is an idle timeout while consuming** — a 3.2s drip feed (≤0.8s gaps) completes under `timeout=2`; a 3s silence under `timeout=1.5` fails with `TimeoutException`.
- **`stream($response, $pollTimeout)`** yields `FirstChunk` → `DataChunk`(s) → `LastChunk`; when idle longer than the poll timeout it yields an **`ErrorChunk` whose `isTimeout()` returns true**.
- **On a timeout chunk, only `isTimeout()` may be called** — every other method (`getContent()`, `isFirst()`, `isLast()`) throws `TimeoutException`. Calling `isTimeout()` marks it handled (destructor-safe).
- **Re-entering `stream()` after timeout chunks resumes reading** — a poll loop (`stream($r, 0.3)` repeated) delivered all 6 drip frames + final result across 6 timeout chunks (validated).
- **`max_duration`** is a *total* cap (aborts even with bytes flowing) — usable as a backstop, but we'll manage the deadline ourselves for a clean error surface.
- **MockHttpClient + `MockResponse(iterable $body)`** drive incremental chunks in tests, including frames **split across chunk boundaries** — the parser must accumulate a buffer (validated).

### Adjacent surfaces

- **Step deadline**: `step.expires_at = now + TASKWEAVER_STEP_TIMEOUT` (600s default) is stamped at `markStepRunning` — the natural upper bound for any single tool call.
- **Worker → controller hop**: `worker/src/ControllerClient.php` uses a hardcoded `timeout => 30` for every request — including `callTool()`. **Long tool calls will be cut off by the worker at 30s** regardless of controller-side changes. (Fix in scope — see §4 Phase 3.)
- **PHP web request cap**: `docker/php.ini` does not set `max_execution_time`; the web default is 30s. A controller request held open for a long tool call may be killed by the engine — must be verified for FrankenPHP worker mode and raised/disabled if needed (§7).
- SQLite handles writes during a held request fine (WAL; existing behavior for the proxy's event logging).

---

## 3. Design

### 3.1 Wire changes (`HttpMcpClient::call`)

```php
$requestId = bin2hex(random_bytes(8));          // unique per call (valid JSON-RPC)
$progressToken = bin2hex(random_bytes(8));      // unique per call

$payload = [
    'jsonrpc' => '2.0',
    'id' => $requestId,
    'method' => 'tools/call',
    'params' => [
        'name' => $toolDef->getName(),
        'arguments' => $arguments,
        '_meta' => ['progressToken' => $progressToken],
    ],
];
```

- `tools/list` also gets a unique id (hygiene; concurrent requests with duplicate ids are invalid JSON-RPC). No `_meta` there — discovery doesn't need progress.
- Keep `initialize` as-is for now (optionally unique-id it later).
- HTTP error statuses (`≥ 400`) surface at the first chunk (headers are known once the stream opens); handle those before parsing frames — same scrubbed error message as today.

### 3.2 Incremental read loop

Replace `getContent(false)` + post-hoc parse with:

```
$response = $client->request(...);              // request timeout = idle safety (e.g. callTimeout)
$buffer = '';
$deadline = min(now + callTimeout, stepDeadline - margin);
$pass = 0;
while ($pass === 0 || (still reading && now < $deadline)) {
    ++$pass;
    foreach ($client->stream($response, $pollInterval) as $chunk) {   // pollInterval ≈ 0.5s
        if ($chunk->isTimeout()) { continue; }               // idle; keep polling (deadline checked above)
        if ($chunk->isFirst())   { check HTTP status ≥ 400 → failure path; continue; }
        if ($chunk->isLast())    { break 2; }                // stream ended
        $buffer .= $chunk->getContent();
        foreach ($parser->extractFrames($buffer) as $frame) {        // splits on \n\n
            if ($frame is notifications/progress) { $onProgress(...); continue; }
            if ($frame['id'] === $requestId) { result-or-error; done; }
            // else: ignore (debug log)
        }
    }
}
// Fallback: no frames seen and buffer is plain JSON → parse as today.
// (Re-entering stream() after timeout chunks resumes reading — validated.)
```

Frame parser details:

- Split on `\n\n`, keep the remainder in the buffer (frames may split across chunks — must handle).
- Join multiple `data:` lines per frame; ignore `event:` names (the SDK always sends `event: message`).
- Only accept a terminal frame whose `id` matches ours; tolerate servers that omit the id (fallback: first `result`/`error` frame seen).
- Tolerate both `text/event-stream` and `application/json` responses — we detect from content, not `Content-Type` alone.

### 3.3 Progress surfacing (v1)

**Listener signature** (small, stable):

```php
call(McpServer $server, ToolDef $toolDef, array $arguments, array $env,
     ?callable $onProgress = null): ToolResult
// $onProgress(float $progress, ?float $total, ?string $message): void
```

Plumbed: `McpClientRegistry::call(..., $onProgress)` → `ToolProxyService` passes a listener that:

1. **Logs** frames: `info` for the first frame and for message-bearing frames (throttled), `debug` otherwise. Format: `MCP progress: run_backup 3/10 — Backing up /etc`.
2. **Aggregates a summary** carried on the `tool_finished` event payload:

```json
"progress": { "frames": 12, "last": {"progress": 10, "total": 10, "message": "done"}, "elapsed_ms": 42000 }
```

- Omitted (or `null`) when no frames arrived — zero change for existing flows.
- **No new event type, no schema change** in v1. (Open question 1 considers a throttled `tool_progress` event for live timeline visibility — recommendation: defer.)

### 3.4 Timeouts, end to end

| Hop | Today | Proposed |
|---|---|---|
| Controller → MCP server (tools/call) | 60s hardcoded | `TASKWEAVER_MCP_CALL_TIMEOUT` (default **600** = step timeout), **capped by `step.expires_at − 5s`** |
| Controller → MCP server (initialize/listTools) | 30s | unchanged |
| Worker → controller (tool call) | 30s hardcoded | `step_timeout`-derived (only for `callTool()`; claim/fetch/etc. stay 30s) |
| Worker → LLM | 300s | unchanged |
| PHP request execution cap | (verify!) | likely `max_execution_time=0` in `docker/php.ini` for long-held requests |

- The step-deadline cap is the key guard: the controller must never sit on a call longer than the step's own life (lazy expiry would otherwise declare it stale mid-call).
- Idle timeouts are handled inside the loop: a timeout chunk is **not** an error — we keep polling until the deadline (validated behavior). Only when the deadline passes (or the connection dies without a terminal frame) do we fail with `MCP call timed out after Ns`.
- `TASKWEAVER_MCP_CALL_TIMEOUT` gets wired through `config/services.yaml` like the other tunables; documented in `.env.example`/SPEC config table.

### 3.5 Explicit non-goals (v1)

- **True streamed tool results** — upstream `StreamableToolResult` (context-loom D5); worker still gets one `CallToolResult`.
- **Server→client notifications / push** (context-loom D23) — separate effort; this PR only consumes frames *on an active request*.
- **Admin UI live streaming** (`STREAMING.md` §6 SSE-to-browser) — deferred.
- **OpenAPI transport** — unchanged (no progress concept there).

---

## 4. Phases

### Phase 1 — Client core (contained to `HttpMcpClient` + tests)

1. Add `_meta.progressToken` + unique ids to `tools/call`; unique id to `tools/list`.
2. Replace the buffered read with the incremental stream loop + frame accumulator + JSON fallback.
3. Optional `?callable $onProgress` parameter.
4. Unit tests (§6) — including the split-frame accumulator and plain-JSON fallback.

### Phase 2 — Plumbing, surfacing, config

1. Interface + registry: thread `$onProgress` through `McpClientInterface` (update `OpenApiMcpClient` + test fakes — mechanical).
2. `ToolProxyService`: listener (log + aggregate) and `tool_finished` summary.
3. `TASKWEAVER_MCP_CALL_TIMEOUT` config + step-deadline cap.
4. Docs: `STREAMING.md` §5 (strike the "out of scope" for the progress channel), `SPEC.md` config table, `WORKER.md` decision #10 note, `.env.example`.

### Phase 3 — Timeouts end to end

1. Worker: use a `step_timeout`-derived per-request timeout for `callTool()` (keep 30s elsewhere).
2. Verify (and, if needed, fix) the PHP/FrankenPHP request execution cap for long-held tool calls (`max_execution_time`) — **blocking for real long-running tools**.
3. Re-check Caddy reverse-proxy buffering for the admin-side response (worker hop is controller→controller; the SSE here is controller→MCP server, no external proxy).

### Phase 4 — Verification

1. New dev fake: `dev/progress-mcp.php` — minimal streamable-HTTP MCP server that completes an `initialize` handshake and, for `tools/call`, streams N `notifications/progress` frames (one every ~250ms) then the result. Also a `--plain` mode that answers with plain JSON (fallback path). Point a seeded MCP server at it and run a task step through the dev rig; watch controller logs show frames and the `tool_finished` payload carry the summary.
2. Optional: repeat against context-loom once its Phase 3 (StreamSink) lands — that's the real counterparty.

---

## 5. Files touched (anticipated)

**Modified**

| File | Change |
|---|---|
| `src/MCP/HttpMcpClient.php` | `_meta` + unique ids; incremental read loop; listener; deadline |
| `src/MCP/McpClientInterface.php` | optional `$onProgress` param on `call()` |
| `src/MCP/McpClientRegistry.php` | thread the listener |
| `src/MCP/OpenApiMcpClient.php` | signature only (no behavior change) |
| `src/Service/ToolProxyService.php` | listener (log + summary); deadline cap; `tool_finished` payload |
| `config/services.yaml` | `TASKWEAVER_MCP_CALL_TIMEOUT` wiring |
| `.env.example`, `.env.dist` | document the new var |
| `worker/src/ControllerClient.php` | long timeout for `callTool()` only |
| `docker/php.ini` | `max_execution_time` (pending verification) |
| `STREAMING.md`, `SPEC.md`, `WORKER.md` | status/config updates |

**New**

| File | Purpose |
|---|---|
| `tests/MCP/HttpMcpClientStreamingTest.php` (name TBD) | streaming + `_meta` + fallback coverage |
| `dev/progress-mcp.php` | dev fake progress server for e2e |

**Tests updated (signature-only):** `tests/Service/ToolSyncServiceTest.php`, `tests/Service/ConnectionTesterTest.php`, `tests/Functional/ToolFinishedEventTest.php` (fakes implement the client interface).

---

## 6. Testing

**Unit — `HttpMcpClient` (`MockHttpClient`)**

- [ ] `tools/call` payload carries `params._meta.progressToken`; unique across calls; `id` unique per call.
- [ ] Streaming response: progress frames + final result → returns result; listener called with expected `(progress, total, message)` values in order.
- [ ] Frame split across chunk boundaries → accumulator still parses both frames.
- [ ] Plain JSON response (no SSE) → parsed exactly as today (fallback).
- [ ] `notifications/progress` without a listener → still swallowed cleanly (no crash).
- [ ] JSON-RPC `error` frame → `ToolResult::failure` with scrubbed message.
- [ ] `isError` content result → failure (existing behavior preserved).
- [ ] Timeout chunks → loop continues (via a fake chunk source or an integration-tagged test); deadline exceeded → clean failure message.
- [ ] `listTools` regression: unique id, no `_meta`, existing assertions intact.

**Functional**

- [ ] `ToolProxyService` with a fake client that fires the listener → `tool_finished` payload carries the aggregated `progress` summary; no summary when no frames.
- [ ] Existing suite green (claim, proxy, conversations, etc.).

**Manual e2e (dev rig)**

- [ ] `dev/progress-mcp.php` + a step tagged to it → controller log shows frames arriving *before* completion; `tool_finished` shows the summary; worker receives the final result normally.

**Style/CI**

- [ ] `php-cs-fixer` clean; Twig unaffected; full controller suite + worker suite green; CI green.

---

## 7. Risks & verification gaps

1. **PHP execution cap on long-held requests** — if FrankenPHP enforces the 30s web default, long calls die mid-stream. Verify first (Phase 3); fix likely = `max_execution_time=0` in `docker/php.ini` + re-verify. *This gates the "long-running" promise.*
2. **SDK drift** — progress semantics verified against `mcp/sdk` 0.8.x (as vendored by context-loom). Re-verify at implementation time against the pinned version; the wire shape we depend on (`params._meta.progressToken`, SSE on the same response) is spec-level, so drift risk is low, but the `event:` framing is worth a fresh look.
3. **Duplicate-frame edge cases** — servers may emit both a result frame and keep the connection open; we finish on the id-matching terminal frame and stop reading (cancels the response). Fine for v1.
4. **Memory** — buffer grows if a server sends a huge non-frame body; cap the buffer (e.g. 10MB) and fail cleanly (mirrors `ContextBudget` discipline).
5. **Session churn** — we initialize per call today; unchanged. If a server rate-limits sessions, that's pre-existing.
6. **Proxy hop timeout coupling** — raising only the client (controller→MCP) without the worker hop keeps the 30s wall. Both must land together for the feature to be usable (Phase 3 is therefore not optional).

---

## 8. Open questions (with recommendations)

1. **Where does progress surface in v1?**
   Recommendation: **log + `tool_finished` summary** (cheap, zero schema). Optional later: throttled `tool_progress` event rows for timeline liveness, or SSE to the admin UI (`STREAMING.md` §6). — *Confirm.*
2. **Default call timeout 600 vs current 60?**
   Recommendation: **600** (= step timeout), configurable, capped by the step deadline. Note: a hung server then holds a FrankenPHP worker slot up to the cap; the step-deadline cap bounds it to the step's own life either way. — *Confirm.*
3. **Worker hop fix in the same effort?**
   Recommendation: **yes** — the feature is moot if the worker cuts at 30s. Small, contained change (per-request override for `callTool` only). — *Confirm.*
4. **`_meta` on `tools/list` too?**
   Recommendation: **no** — progress is a `tools/call` concept; discovery stays as-is (unique id only). — *Confirm (acceptance criteria say "optionally").*
5. **New event type for progress milestones?** (e.g., `mcp_progress` for message-bearing frames only)
   Recommendation: **defer** — revisit if the timeline feels dead during long calls in practice. — *Optional input.*

---

## 9. Acceptance criteria mapping (issue #28)

| Criterion | Where |
|---|---|
| `tools/call` payload includes `_meta.progressToken` (unique per call) | §3.1 + unit test |
| Response consumed incrementally; `notifications/progress` recognized/forwarded | §3.2 + unit tests |
| Plain JSON responses still handled | §3.2 fallback + unit test |
| `HttpMcpClientTest` updated (assert token; streaming-frame test) | §6 |
| Timeout raised/configurable | §3.4 (`TASKWEAVER_MCP_CALL_TIMEOUT` + step cap) |
| Regression: OpenAPI client + `tools/list` unaffected | §6/§5 (signature-only change) |
