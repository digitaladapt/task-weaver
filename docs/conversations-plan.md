# Conversations & Follow-ups — Design Plan

Status: **implemented** (branch `feat/conversations-followups`) — see migration `Version20260911130000`, `ConversationService`, `ConversationController`, claim pass-1, and the UI under `templates/admin/conversations/`.
Revision: **2** (after review) — replies are *transient* runs: create → run → log → hard-delete. No persistent per-conversation task/step/event residue.
Scope: conversations as first-class standalone objects, follow-ups as a convenience action, step-level `run_id`, and messages-as-steps for replies.

---

## 1. Summary

TaskWeaver today is: tasks → steps → events (an audit trail). We are adding a **chat layer on top** without changing the execution model:

- A **conversation** is a thread of **messages**, completely standalone — nothing requires a task to exist.
- A **follow-up** is a convenience button on a `step_*` event: it creates a conversation seeded with that event's **response** and redirects you there. It does *not* replay tool calls.
- Each **message carries tags**, so you can add/remove tags mid-conversation and thereby adjust which **tools are available for a given response**.
- **Messages hold end-results**; a per-message **tool log** holds the details of what the LLM did (tool calls, args, results) before producing that response.
- A chat **reply is functionally a step**: the controller presents it to a worker exactly like a task-step, so the worker can't tell the difference and needs **no changes**.

The core trick: a reply runs as a **transient one-step task**. When a message needs an answer, the controller creates a throwaway `Task` (one final `Step`) purely to drive the existing worker pipeline. When the run reaches a terminal state, the controller **materializes the reply** (assistant message + per-message tool log, copied from the run's events) and then **hard-deletes the task**, which cascades away the step, events, and tool calls. Nothing from a conversation ever lingers in `task`, `step`, or `event` tables — the durable record is `message` (+ `message_tool_log`).

---

## 2. Locked decisions (from design discussion)

| # | Decision |
|---|----------|
| D1 | Conversations/messages are standalone tables; follow-up is only a *convenience wrapper*, no task linkage required. |
| D2 | Follow-up button is offered **only on `step_*`-type events** (`step_started`, `step_completed`, `step_failed`), final step or not. It works with the **response**, not the tool-calling trail that led up to it. |
| D3 | Optional `source_run_id` on a conversation (provenance), backed by the new step-level `run_id`. |
| D4 | No regeneration. Conversations are **linear**: each user message produces exactly one reply. No re-run buttons, no branching. |
| D5 | Context follows the existing step-context logic: **no previous thinking blocks, no previous tool results** — only the rendered user/assistant text. Token-aware prune/compact of history comes later (same as steps). |
| D6 | A reply is a **throwaway task-step** claimed by a worker through the *existing* API. In claim order: **unanswered messages first**, then ready steps — conversations are high-priority without disrupting the task flow. |
| D7 | The worker **cannot tell** a conversation-reply from a task-step. API shape unchanged (claim returns `{task, step}`; fetch returns a normal task with one final step). |
| D8 | `MessageToolLog` has **no run_id** — it hangs off `message_id`, which is exactly the run it belongs to. |
| D9 | Future: "historical context" vs "current user input" will be **structurally separated** — and when that happens it applies to **both** steps and messages, not just conversations. |
| D10 | Reply runs are **hard-deleted** after the reply is logged (no soft-delete, no residue). The `message` + `message_tool_log` rows are the durable record. |

---

## 3. Data model

### 3.1 New tables

#### `conversation`

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | PK |
| `name` | string(255) | e.g. `Follow up to Quick weather · GetWeather · step_completed` |
| `source_run_id` | string/null | Provenance: the run the seed came from (D3). Optional; `null` for hand-created conversations. |
| `source_event_id` | UUID/null | The `step_*` event the follow-up was created from (optional provenance). |
| `created_at` / `updated_at` | datetime | `updated_at` touched on every message; used for sorting. |
| `archived_at` | datetime/null | Soft-archive for the UI. Not in v1 UI unless trivial. |

No `task_id`, no persistent reply-task pointer — a conversation owns only its messages (D1, D10). Provenance is via `source_run_id`/`source_event_id` only.

#### `message`

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | PK |
| `conversation_id` | FK → Conversation, CASCADE | |
| `role` | string(16) | `user` \| `assistant` |
| `content` | text | The **end result** only. For `assistant`: the final answer text. No thinking blocks, no tool traces. |
| `tags` | JSON array | Tool/capability tags governing the *response to this message* (see §6.2). |
| `status` | string(16) | `queued` \| `running` \| `completed` \| `failed` (user messages). Assistant messages are always `completed`. |
| `error` | text/null | Fail reason when `status = failed`. |
| `is_seed` | boolean | Follow-up seed message (rendered specially in UI as "seeded from event"). |
| `source_event_id` | UUID/null | For seed messages: the event it was copied from. |
| `reply_task_id` | UUID/null | Audit provenance: the id of the throwaway reply task that produced the assistant reply. The task itself is **deleted** after logging (D10), so this is just a reference to a gone row. |
| `created_at` / `completed_at` | datetime/null | |

#### `message_tool_log`

Holds the details of what the LLM did **before** generating a response. One row per tool execution (external and internal), copied from the run's events when the reply completes (see §7).

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | PK |
| `message_id` | FK → Message, CASCADE | The **assistant** message this tool log belongs to (D8 — the message *is* the run). |
| `tool_name` | string(255) | e.g. `weather.get`, `terminal` |
| `kind` | string(16) | `external` \| `internal` |
| `arguments` | JSON | Args the LLM requested |
| `result` | JSON/null | Tool result (external) or recorded result (internal) |
| `error` | text/null | |
| `status` | string(16) | `completed` \| `failed` \| `denied` |
| `created_at` | datetime | |

### 3.2 Modified tables

#### `event` — add `run_id`

| Field | Type | Notes |
|-------|------|-------|
| `run_id` | string(36)/null | Groups one **step execution** (one run): the initial `llm_call` mints it, every subsequent `tool_*` and `step_*` event for that execution copies it. Indexed. |

#### `step` — add `run_id`

| Field | Type | Notes |
|-------|------|-------|
| `run_id` | string(36)/null | The current execution's run id. **Minted when the step is marked `running`**; cleared on reset for the next run; retained on terminal `step_*` events for audit. |

Mint here rather than only on the `llm_call` event because `step_started` is logged *before* the first event is registered — minting at `markStepRunning` guarantees **every** event of the execution carries the id (the worker's first `llm_call` still gets it, satisfying "set on the initial `llm_call`"; the id is simply born one log-line earlier so `step_started` is covered too).

#### `task` — add `conversation_id`

| Field | Type | Notes |
|-------|------|-------|
| `conversation_id` | UUID/null, FK → Conversation, **unique** | Non-null ⇒ this is a **transient reply task** for that conversation (D10). Unique ⇒ **at most one live reply run per conversation** at any time. Hard-deleted after the reply is logged. Excluded from the admin Tasks list/dashboard counts. |

---

## 4. `run_id` lifecycle

1. `TaskWorkflowService::markStepRunning()` mints `run_id = uuid` on the `step` and stamps it on the `step_started` event.
2. `registerEvent()` (the initial `llm_call`) copies `step.run_id` onto the new event.
3. `ToolProxyService::logEvent()` (tool_requested / tool_finished / tool_internal) and `TaskWorkflowService::log()` (step_completed / step_failed) copy `step.run_id` onto every event they create.
4. `resetForRun()` / scheduler's step reset clear `step.run_id` so the next execution mints fresh.
5. Follow-up sets `conversation.source_run_id = event.run_id`.

No worker change: the worker never sees `run_id` (it's controller-side audit grouping).

---

## 5. Reply execution: transient messages-as-steps

### 5.1 Per-reply throwaway run

When a user message needs a reply, `ConversationService::ensureReplyRun($conversation)` creates a **transient run** (if the conversation doesn't already have one — enforced by the unique `task.conversation_id`):

```
Task:    name = "Reply to <conversation name>", schedule = null, priority = 100,
         conversation_id = <conversation.id>, status = ready
Step:    name = "Reply", is_final = true, sort_order = 0,
         description = rendered transcript (see §6.1), tags = response tags (see §6.2)
```

- `ensureReplyRun()` is called when a message is posted, and again when a run finishes (to drive the next queued message) and at claim time as a safety net (covers a crash between post and create).
- The run is claimed by a worker via the **existing** claim/fetch/run/complete pipeline — no new endpoints, no worker changes (D6/D7).
- On a terminal state (`completed` or `failed`), `ConversationService::onStepTerminal()`:
  1. Materializes the assistant `Message` (or marks the user `Message` `failed` with the reason);
  2. Copies the run's tool executions into `message_tool_log` rows (see §7);
  3. **Hard-deletes the reply task** with `em->remove($task)` — cascade removes the step, its events, and their tool calls (D10). **No soft-delete.**
  4. If the conversation still has queued messages, `ensureReplyRun()` creates the next run.

Result: during a reply's lifetime there is exactly one extra task/step in the DB (invisible to the admin UI, and only for the duration of the run). Afterward, **nothing** remains in `task`, `step`, `event`, or `tool_call` tables — the durable record is `message` + `message_tool_log`.

### 5.2 Claim priority (two-pass, non-disruptive)

`ClaimService::claimFor()` becomes:

1. `expireStaleSteps()` (unchanged) — stale running steps are failed first; if the step belongs to a reply task, the failure also flows into `onStepTerminal()` cleanup.
2. **Pass 1 — conversations first:** find claimable **reply tasks** (`task.conversation_id IS NOT NULL`, task `ready`, single step `pending`, not stale) — oldest conversation first (`conversation.updated_at` or `task.created_at`).
   - Apply the **same** sandbox-capability tag matching (`workerCoversStep`) as normal steps. External-tool tags are still proxied, so any worker can answer a weather-tagged reply.
   - If found → return `{task, step}` — **identical claim shape** (D7).
3. **Pass 2 — normal tasks:** existing `findClaimable(...)`, now excluding conversation tasks (`task.conversation_id IS NULL`).

"Without disrupting the flow": conversation replies are only *ahead of* normal steps when one is actually pending; once all conversations are handled, the queue drains normally. No starvation risk in practice (a worker loop always returns to pass 2 when pass 1 is empty). If we ever see reply floods starving tasks, a simple round-robin/fairness toggle can be added — **not v1**.

### 5.3 Worker side — zero changes

The worker:
- claims → gets `{task, step}` as usual
- fetches `/api/worker/task/{id}` → sees a one-final-step task; `description` already contains the full turn context (§6.1); `tools` are the tag-matched schemas + sanctioned internal tools
- runs the normal LLM loop, calls tools via the existing proxy, completes via `/complete`

### 5.4 Message status & failure

- `POST /conversations/{id}/messages` → creates user `Message` (`queued`) + `ensureReplyRun()`; returns `{message_id, status: "queued"}` — plus an explicit "waiting for worker" affordance in the UI so a parked reply doesn't look dead.
- Claim → message `running`.
- Complete → user message `completed`, assistant `Message` (`completed`) appended, run hard-deleted.
- Fail (worker-reported or stale expiry) → user message `failed` with `error`; run hard-deleted; UI shows **Retry** (re-queues the *same* message — that's a retry, not regeneration; D4 intact).
- Multiple queued messages: answered **FIFO**, one at a time (linear, unique `conversation_id` enforces it). The reply to the oldest queued message is created first; later ones are created when the previous run finishes.

### 5.5 Crash-safety

- **Worker crash mid-run** → stale expiry fails the step → `onStepTerminal()` materializes the failure and deletes the run.
- **Controller crash after completion but before deletion** → on the next controller write (claim / expiry sweep / scheduler tick), `ConversationService::sweepOrphanReplyRuns()` deletes any `conversation_id` task that is terminal (completed/failed) and whose assistant message already exists (idempotent — materialization happened, just clean up). If a terminal task exists but no assistant message, materialize first, then delete.
- **Crash before `ensureReplyRun()` from a queued message** → pass 1's safety-net call creates it lazily at claim time.

---

## 6. Context & tags

### 6.1 What the worker sees (context assembly)

`ensureReplyRun()` renders the step `description` as:

```
Current date/time: … (utc) … timezone …

## Conversation history
user: <prior message content>
assistant: <prior reply content>
… (last N messages, oldest-first)

## Current message
user: <this message content>
```

Rules:
- **User/assistant content only.** No thinking blocks, no tool traces (D5) — the tool log stays in `message_tool_log`, not the prompt.
- History cap v1: last **20** messages or ~8k chars (env-tunable); **token-aware prune later** (same `ContextBudget` concept the worker already applies — when history compaction lands, it lands for steps *and* conversations together, per D9).
- Rendered controller-side into the description so the **worker code stays untouched** — the description is just text, like any step. No API change.
- D9 note: v1 keeps it as text; the future split is structured `context` + `input` fields on the fetch payload — added to **steps and messages alike**.

### 6.2 Message tags → response tool availability

- The **latest queued user message's tags** govern its reply's step tags (and therefore the toolbox, via the existing `ToolResolver::schemasForStep`).
- If the latest user message has **no tags**, fall back to the **conversation's seed tags** (copied from the source step's tags at follow-up time; empty for hand-created conversations).
- Editing a message's tags mid-conversation (UI: tag chips on each message) only affects the **next** response — that's exactly "adjust tools for any given response message."
- Step tags are set at `ensureReplyRun()` time, so a queued message's tags are honored even if an edit happens before claim.

### 6.3 Follow-up seeding

On `POST /conversations/follow-up` with an `event_id` of type `step_*`:

1. Load event (must be `step_*`; reject otherwise).
2. Create `Conversation`:
   - `name`: `Follow up to <task.name> <step.name> <event.type>`
   - `source_run_id`: `event.run_id` (if any — D3)
   - `source_event_id`: `event.id`
3. Create seed `Message`:
   - `role`: `user`, `is_seed`: true, `source_event_id`: `event.id`
   - `tags`: the step's tags (so the follow-up context keeps its tool access)
   - `content`: the **response** — for `step_completed`: `payload.result.summary` (string) if present, else pretty JSON of `payload.result`; for `step_failed`: `payload.reason`; for `step_started`: pretty JSON of `payload` (there's no response yet, so seed is mostly a marker).
4. Redirect to `/conversations/{id}`.

The seed is rendered in the UI with a small "seeded from <event>" badge. The human then types the actual follow-up as a normal message; the reply run builds on the transcript. **No tool-call history is copied** (D2) — only the response.

---

## 7. Tool-log materialization (then delete)

When a reply run terminates (`complete`/`failed`), `onStepTerminal()` copies the run's tool executions into `message_tool_log` for the **assistant message**:

- Walk the reply task's events for `step.run_id` (the just-ended run), in timestamp order.
- For each `tool_requested` event → one `MessageToolLog` row: `tool_name`, `kind = external`, `arguments` from payload, `result` from the associated `ToolCall.response` (or `error`/`status`), `status`.
- For each `tool_internal` event → one row: `kind = internal`, payload args/result.
- `message_id` = the assistant message (D8; no run_id needed — one reply = one run = one message).

Then the reply task is **hard-deleted** (§5.1). Because `message_tool_log` is a **copy**, deleting the source task/events is safe: conversations remain standalone, and if a normal task is ever deleted/restored, conversation logs are unaffected.

Messages stay **lean** (content = final answer only); the tool log holds the machinery — matching the "messages hold end-results" principle.

---

## 8. Admin UI

- **Nav**: add `Conversations` link in `base.html.twig`.
- **`/conversations`** (`ConversationController::index`): list — name, updated_at, last message preview, status badge (`waiting for worker` if a message is queued/running, `idle` otherwise).
- **`/conversations/{id}`** (`show`): thread view (user/assistant bubbles), seed badge on `is_seed`, status pills per message (queued/running/completed/failed), **tag chips per message** (add/remove → `PATCH /conversations/{id}/messages/{messageId}`), reply input, **tool-log drawer** per assistant message (renders `message_tool_log`, collapsible).
- **Task show** (`templates/admin/tasks/show.html.twig`): on each `step_*` event in the recent-events timeline, a small **Follow up** button → `POST /conversations/follow-up` (CSRF) → redirect to the conversation. Only `step_*` types get the button (D2).
- Transient reply tasks are excluded from the Tasks list and dashboard counts (§3.2); they exist only for the duration of a run.

---

## 9. Files touched (implementation checklist)

**New**
| File | Purpose |
|------|---------|
| `src/Entity/Conversation.php`, `Message.php`, `MessageToolLog.php` | Entities + repos |
| `src/Repository/ConversationRepository.php`, `MessageRepository.php`, `MessageToolLogRepository.php` | Queries (`findWithQueuedMessage`, etc.) |
| `src/Service/ConversationService.php` | ensureReplyRun, followup, postMessage, onStepTerminal, materializeReply, sweepOrphanReplyRuns |
| `src/Controller/ConversationController.php` | index/show/message post/message tag patch/follow-up |
| `templates/admin/conversations/{index,show}.html.twig` | UI |
| `migrations/Version2026xxx.php` | new tables + `event.run_id`, `step.run_id`, `task.conversation_id` |

**Modified**
| File | Change |
|------|--------|
| `src/Entity/Event.php` | `run_id` column |
| `src/Entity/Step.php` | `run_id` column |
| `src/Entity/Task.php` | `conversation_id` nullable unique FK |
| `src/Service/TaskWorkflowService.php` | mint `run_id` in `markStepRunning`; copy in `log()`; clear in reset |
| `src/Service/ToolProxyService.php` | copy `run_id` into `logEvent()` |
| `src/Service/ClaimService.php` | two-pass claim (conversations first, then normal tasks) |
| `src/Repository/StepRepository.php` | `findClaimableByConversation()` (reuses `workerCoversStep`); exclude conversation tasks from normal `findClaimable` |
| `src/Repository/TaskRepository.php` | exclude `conversationId IS NOT NULL` from admin queries |
| `src/Controller/StepCompleteController.php`, `StepController.php` | call `ConversationService::onStepTerminal()` |
| `templates/base.html.twig` | nav link |
| `templates/admin/tasks/show.html.twig` | Follow up button on `step_*` events |

`worker/` — **no changes** (D7).

---

## 10. Tests

- Unit: `ClaimService` order (conversation reply wins over ready step when pending; falls through when none), tag matching on reply steps (external-tag proxying still applies).
- Unit/functional: follow-up creates conversation + seed message with correct name/content/tags; rejects non-`step_*` events.
- Functional: post message → claim → complete → assistant message + `message_tool_log` rows, **and the reply task + step + events are gone** (assert no rows in `task`/`step`/`event`/`tool_call` for the conversation's run after completion).
- Functional: failure path marks message `failed` with retry, **and the run is cleaned up too**.
- Functional: multiple queued messages answer FIFO, one run at a time (unique `conversation_id`).
- Regression: existing claim/step flows unchanged when no conversations exist.

---

## 11. Out of scope / future

- **Regeneration / branching** (D4) — deliberately excluded.
- **Token-aware history compaction** — when it lands, it lands for steps + messages together (D9).
- Structured separation of `context` vs `current input` in the fetch payload (D9) — both steps and messages.
- Conversation-to-conversation "follow up" (follow up on an assistant message inside a conversation to spawn a new thread) — natural later extension of the same seeding action.
- Streaming replies / typing indicators — separate effort (v2 roadmap already lists streaming LLM proxy).
- Per-conversation model/provider selection — belongs to the v2 "LLM model types" item.

**Rejected (recorded):** the earlier idea of a *persistent* hidden reply task per conversation, re-armed per turn with `resetForRun()`. It left a task/step row per conversation and, worse, an ever-growing pile of `event`/`tool_call` rows — exactly the ghost clutter D10 exists to prevent. Transient runs fix both: no residue, and the durable record lives in `message`/`message_tool_log`.

---

## 12. Open questions (minor; can be settled at implementation)

1. **Seed role**: plan assumes the seed message is `role: user` with an `is_seed` badge (it behaves as quoted context, and the human's actual question is the newest user message). Alternative: seed as `role: assistant` (looks more like a chat history start). I lean `user` + badge.
2. **Tag fallback**: plan assumes latest-user-message tags, else conversation seed tags. Confirm.
3. **Retry on failure** is in-scope as a retry (not regeneration) — confirm acceptable.
