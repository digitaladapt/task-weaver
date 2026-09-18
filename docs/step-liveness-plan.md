# Step Liveness — Two-Clock Deadlines & Cooperative Worker Abort

**Status:** Phase 1 ✓ implemented · Phase 2 ✓ implemented (branch `feature/step-liveness-clocks`) · Phase 3 partially done · 2026-09-17
**Issue:** *(TBD)*
**Interlocks:** SPEC.md §Configuration / §Stale Step Expiry · WORKER.md §9 + decision #4 · `mcp-progress-plan.md` §3.4 (timeout ladder) · `docs/model-selection-plan.md` §5.1 (config precedence)

---

## 1. Why

Today a step has exactly **one** clock: `Step.expires_at = markStepRunning + TASKWEAVER_STEP_TIMEOUT` (600s), stamped in `src/Service/TaskWorkflowService.php:109`. It is an *end-to-end* budget and nothing else. That leaves two gaps:

1. **A stalled step burns its whole budget.** An LLM that accepts the request and then goes quiet, or a tool call that hangs, leaves the step `running` for the full 600s. Nothing distinguishes "making progress" from "wedged" until the e2e deadline lands.
2. **Enforcement is server-side only, and late.** The worker is never told what its budget is, so it cannot act on it. `step_timeout` is issued in the provision config (`src/Service/ProvisionService.php:90`) but **no worker code reads it** — grep for `step_timeout` under `worker/` returns zero hits. The worker discovers the deadline only by being *401'd*, at which point its only move is to drop the step and lose whatever it had produced.

**Goal.** Add a second, configurable clock — **maximum time between activities** — and hand both budgets to the worker so it can stop *early, on purpose*, transmitting partial results instead of being cut off empty-handed.

The three behaviours we want:

| Situation | Today | Wanted |
|---|---|---|
| LLM accepts, then goes silent mid-stream | Step wedges until 600s e2e | Worker notices ~`idle − grace` of silence, cancels the stream, completes with the partial text |
| LLM is healthy, calling tools, but the step is old | Step killed dead at 600s | Worker stops at ~`600 − grace`, completes with results so far |
| Worker process dies outright | Lazy e2e expiry at 600s | Controller idle backstop sweeps it sooner (no worker cooperation needed) |

---

## 2. Verified facts (2026-09-17)

Checked against this tree at `v0.2.3` (`5f94c90`).

**Controller**

- One deadline, one stamp: `TaskWorkflowService.php:109` is the *only* `setExpiresAt()` call outside seed/reset.
- The selection query already treats stale-running as claimable: `src/Repository/StepRepository.php` — `where('s.status = :pending OR (s.status = :running AND s.expiresAt < :now)')`, and `findStaleRunning()` at `:189`.
- Expiry is **lazy** — `expireStaleSteps()` has exactly one caller: `src/Service/ClaimService.php:50`. No sweeper, no cron. `SchedulerTickCommand` does not touch steps.
- Event keys are gated by the deadline: `Event::hasValidKey()` (`src/Entity/Event.php:127`) → `!$step->isExpired($now)` → `Step::isExpired()` at `src/Entity/Step.php:243`. Enforced once, in `src/Controller/ToolController.php:50` → 401.
- **Gap:** `StepCompleteController` and the `status` PATCH never check `isExpired()`. The status endpoint returns `expires_at` (`src/Controller/StepController.php:75`) purely informationally. SPEC.md:422/424 claims denial spans "any rejected tool call, status transition, or `complete`" — the code does not do that. A late `complete` is **accepted** today.

**Worker**

- Run loop: `worker/src/Command/RunCommand.php`. Per step: `fetchTask` → `markRunning` (`:302`) → `registerEvent` (`:306`) → LLM/tool loop → `complete` (`:452`).
- The loop is bounded by rounds (`MAX_LLM_ROUNDS = 50`, `RunCommand.php:58`) and by context budget — but by **no clock**. There is no notion of "time remaining".
- Config precedence already exists and is the right hook: `RunCommand::resolveVar()` = CLI flag → env var → controller-issued value → hardcoded fallback.
- `LlmClient` (`worker/src/LlmClient.php`): streaming by default, `'timeout' => 300` at `:92`, `:230`, `:273`. `postWithRetry()` (`:174`) **already knows how to salvage partial output** — on the final retry it returns `$e->partialContent` / `$e->partialToolCalls` rather than throwing. That salvage path is exactly what the idle abort needs; it currently only triggers on transport failure.
- `ControllerClient` uses a hardcoded `'timeout' => 30` (`worker/src/ControllerClient.php:27`) for every request.

**Symfony HttpClient mechanics** (already validated for `mcp-progress-plan.md` §2, reused here)

- `timeout` is an **idle** timeout while consuming a stream, not a total cap.
- `stream()` yields an `ErrorChunk` with `isTimeout() === true` when idle longer than the poll timeout; **only `isTimeout()` may be called** on it (everything else throws).
- Re-entering `stream()` after a timeout chunk **resumes** reading. A poll loop therefore gives us a precise "bytes have stopped arriving for N seconds" signal mid-stream for free.

---

## 3. Design

### 3.1 The two clocks

| Clock | Scope | Reset by | Stored as |
|---|---|---|---|
| **E2E** (existing) | absolute, from `markStepRunning` | *nothing* — fixed budget | `Step.expires_at` |
| **Idle** (new) | rolling | **any activity, including streamed-chunk progress beats** | `Step.idle_expires_at` |

`idle_expires_at` is a stamped absolute deadline, refreshed on each activity — deliberately mirroring the existing `expires_at` pattern rather than storing a `last_activity_at` + injecting config into the repository layer. That keeps the selection query a plain indexed comparison and matches how expiry is already done.

Since `idle_expires_at − idle_timeout = last_activity`, no separate column is needed; the admin UI derives "idle since" for display.

**What counts as activity:** any event recorded for the step. That is a clean, single-sentence invariant:

> Writing any event for a step refreshes its idle deadline.

Concretely, on the controller: `markStepRunning` (initial stamp), `registerEvent` (LLM call starting), the new worker **progress beat** while streaming, `ToolProxyService::handleToolCall` (`tool_requested` and `tool_finished`), `ToolProxyService::logInternalToolCall`.

The beat is throttled worker-side so a fast stream cannot turn into an HTTP flood: at most one report per `max(5, idle/3)` seconds of streaming, and only while chunks are actually arriving.

**The LLM-silence problem, solved by progress beats.** In *direct* mode the controller sees nothing while the worker is mid-generation — a naive idle clock would fire on a perfectly healthy long generation. Earlier drafts proposed a static `llm_allowance` the controller would have to add blindly. Instead, **the worker reports progress**: while it is receiving streamed chunks it periodically POSTs a keepalive for the step, and each report refreshes `idle_expires_at` exactly like any other activity. Streamed tokens are therefore a *live* liveness signal rather than a guess.

The failure modes fall out correctly:

- **Healthy slow model** → chunks keep flowing → worker keeps beating → `idle_expires_at` keeps moving → no false trip.
- **Wedged model** (accepted the request, then silence) → chunks stop → worker's own clock trips first (`idle − grace`) → it aborts and submits partial output.
- **Dead worker** → beats stop → controller idle clock fires after `idle_timeout` → step reaped. This is the case a static allowance handled worst: it would have waited out the full allowance before noticing a corpse.

The beat is strictly cheaper than a ping loop (it rides the worker's existing chunk callback, throttled to at most one report per `idle/3`), and it keeps the controller's clock *accurate* rather than generous. Worker-side, the beat is a plain `POST /api/worker/progress`; it is **best-effort** — a failed beat is logged and ignored, never fatal to the step (§3.9).

### 3.2 Which clock trips first

Both are always live; the first to pass wins.

```
t=0     markStepRunning          e2e ────────────────────────────────────► 600s
                                  idle ──► 120s
t=10    LLM response             idle ──► 130s
t=12    tool call → tool_finished idle ──► 132s
t=40    LLM response             idle ──► 160s
...
t=590   {{ e2e trips }}
```

A healthy step that keeps producing activity trips the **e2e** clock. A wedged step trips **idle**. `TASKWEAVER_STEP_GRACE` is subtracted from both before the *worker* acts (§3.4); the controller's own enforcement uses the raw deadlines, so the worker always gets first crack.

### 3.3 Grace: durations, not timestamps

The worker must act *before* the controller's deadline so it can still transmit. Two reasons to send **durations** rather than the timestamps:

1. **Clock skew.** Worker and controller are separate containers; wall clocks may differ. A duration (`you have 587 seconds left`) is immune; an absolute timestamp is not.
2. **Freshness.** `markStepRunning` is the instant the step starts, so "remaining" at response time is exactly the budget.

The existing status response (`src/Controller/StepController.php:71-77`) gains a `budget` block:

```json
{
  "ok": true,
  "step_id": "...",
  "status": "running",
  "expires_at": "2026-09-17T15:30:00+00:00",
  "idle_expires_at": "2026-09-17T15:22:15+00:00",
  "budget": {
    "e2e_remaining": 597,
    "idle_timeout": 120,
    "grace": 15
  }
}
```

The worker arms two local timers from the moment it reads the response:

```php
$t0 = microtime(true);
$e2eDeadline  = $t0 + $budget['e2e_remaining'] - $budget['grace'];
$idleDeadline = $t0 + $budget['idle_timeout']  - $budget['grace'];  // re-armed on activity
```

`e2e_remaining` is computed controller-side as `expires_at − now`, so it is inherently ≤ `step_timeout` and absorbs any delay between stamping and the worker receiving it.

### 3.4 What the worker does when a clock trips

This is the point of the whole exercise — graceful degradation, not a 401.

**Idle trips mid-stream** (the LLM accepted the request, then went quiet):

```
silence > (idle_timeout − grace)?  →  cancel() the response
                                   →  keep the assembled partial content / tool-call deltas
                                   →  complete(partial: true, reason: "idle timeout: LLM silent 105s")
```

`LlmClient` gains an idle detector in its existing `stream()` loop: count accumulated `isTimeout()` chunk time, abort past the threshold. The partial-salvage plumbing already exists in `postWithRetry()`; this route returns it as a **normal (non-error) result** carrying `truncated: true` + `reason`, rather than only on retry exhaustion.

**E2E trips between rounds** (healthy, but out of time):

```
before each chat() and each tool call:
  now > e2eDeadline  →  break the loop, complete(partial: true, reason: "step deadline reached at 585s")
```

**Both trips are checked at the top of the loop and inside the stream.** No new abort path is needed for tools — a tool call that would exceed the budget is simply not issued; the loop breaks first.

### 3.5 Partial completion is `completed`, not `failed`

A truncated step submits via the existing `complete` route with a marker:

```json
POST /api/worker/step/{taskId}/{stepId}/complete
{ "result": { "summary": "…partial…" },
  "partial": true,
  "reason": "idle timeout: LLM silent 105s" }
```

Rationale:

- The user's requirement is explicit — *transmit the incomplete response*. Failure semantics (SPEC.md §Failure Semantics) persist **no result** for a failed step, so marking it `failed` would throw away exactly what we're trying to save.
- It preserves the existing failure-forward philosophy: the step completes, the shape resolves normally, and the **final step** decides how to present a partial input — the same way it already decides how to present a failed one.
- The `step_completed` event payload records `partial: true` + `reason`, so the audit trail is honest about it, and the final-step envelope can carry the marker through to the consumer.

The route keeps its existing authority rule: a late `complete` past the controller's deadline is rejected (`409`) once §3.7 lands, and the worker abandons — unchanged behaviour. The grace margin is what keeps this off the happy path: the worker submits ~`grace` seconds early, so it is normally well inside.

### 3.5a The progress endpoint

```
POST /api/worker/progress/{taskId}/{stepId}     (Tier-1 worker key)
{ "event_id": "..." }                            →  200 { "ok": true, "idle_expires_at": "..." }
```

Refreshes the step's idle deadline and nothing else — it writes no event row (that would defeat the purpose by flooding the audit trail and the event table). It is authorized the same way as every other worker call (worker key + step running + not already stale) and returns the refreshed deadline so the worker can re-arm its local clock from the authoritative value.

Not to be confused with SPEC.md's rejected "keepalive beats": that decision was about a *periodic timer* kept alive for its own sake (no work happening). A progress report is emitted only while work is demonstrably happening, and it buys the controller an accurate view instead of a generous guess. Worth a Decisions Log entry (§ Phase 3).

### 3.6 Controller-side backstop

`Step.idle_expires_at < now` becomes a second staleness condition, evaluated lazily exactly like the e2e one:

- `StepRepository::findClaimable()` / `findClaimableByConversation()` — extend the `where` to `(s.expiresAt < :now OR s.idleExpiresAt < :now)`.
- `findStaleRunning()` — same extension, and it must report **which** clock tripped so `expireStaleSteps()` writes an accurate reason (`"worker timeout: step expired at …"` vs `"worker idle timeout: no activity since …"`).
- `Step::isExpired()` → split into `hasExpiredE2e()` / `hasExpiredIdle()` plus `isStale()` (either). `Event::hasValidKey()` switches to `!isStale()`, so **the 401 gate covers the idle clock automatically** — one method, both clocks.

Because the worker acts `grace` seconds early, this backstop only fires for a worker that is gone. That is the intended layering.

### 3.7 Piggyback: make `complete` and status transitions actually honour the deadline

SPEC.md:422 and :424 already *claim* this and the code does not do it (§2 gap). Since this change is entirely about deadline authority, close it here: `completeStep()` and the `status` PATCH are gated on `!$step->isStale(now)`, rejecting with `409` past either deadline. Without this, a zombie worker that ignored its own clocks could still write results after the controller declared the step stale — which would make the new idle clock advisory rather than real.

### 3.8 Config

| Variable | Default | Meaning |
|---|---|---|
| `TASKWEAVER_STEP_TIMEOUT` | `600` | **E2E** budget — seconds a step may run end-to-end. *(existing)* |
| `TASKWEAVER_STEP_IDLE_TIMEOUT` | `120` | **Idle** budget — max seconds of silence between activities. *(new)* |
| `TASKWEAVER_STEP_GRACE` | `15` | Head start the worker takes: it aborts at `deadline − grace` so it can still transmit. *(new)* |
| `TASKWEAVER_STEP_PROGRESS_INTERVAL` | `0` (auto: `idle/3`) | Minimum seconds between progress beats while streaming. `0` = derive from `idle`. *(new, optional)* |

Validation, enforced at boot next to the existing kernel guards (there is already a `KernelProdGuardTest`):

- `grace < idle_timeout` — otherwise the worker's idle window is non-positive.
- `idle_timeout < step_timeout` — otherwise the idle clock can never trip first and the setting is a silent no-op.
- `grace < step_timeout`.

Fail loudly rather than silently degrading, matching the existing prod-guard philosophy.

Both new values ride in the provision config (bringing `step_timeout` to life alongside them) **and** in the per-step `budget` block. Worker precedence follows the existing `resolveVar` chain: `--step-idle-timeout` / `TASKWEAVER_STEP_IDLE_TIMEOUT` → provision config → built-in default.

### 3.9 Worker-side checking is cooperative, never authoritative

Worth stating plainly: the worker clocks are **best-effort mitigations for a worker's own slowness**, not a security boundary. A malicious worker can ignore them. The controller's `expires_at` / `idle_expires_at` remain the authority — the worker is simply given the courtesy of knowing where the line is, so the common failure mode degrades gracefully instead of losing work.

### 3.9a Staleness freezes activity (found in implementation)

Because the deadline is *stamped and refreshed* by the same service, an obvious hazard appears: if any activity route rolls the idle clock forward, a zombie worker can call that route in a loop and keep a dead step technically alive forever — dodging the very reaping the clock exists to trigger. Two guards close it:

1. **`touchActivity()` refuses to roll a stale step's clock.** The choke point every activity path goes through, so no route can accidentally re-arm a dead step.
2. **`POST /api/worker/tool/internal` now validates the event key.** It authenticates with the *Tier-1 worker key*, not the event key, so it previously bypassed `Event::hasValidKey()` entirely — a genuine gap that predates this change. It is now gated the same way the external tool route is (401 past either deadline).

Net effect: once stale, a step accepts nothing but the expiry sweep. A denied tool call is likewise not activity — rejection writes no event, so the clock correctly does not move.

### 3.10 What we are deliberately *not* doing

- **No *idle-time* keepalive.** A bare `ping` on a timer (nothing happening, just "still here") remains rejected — that is what SPEC.md §Stale Step Expiry and WORKER.md decision #4 ruled out. The progress beat in §3.5a is different: it fires only while chunks are demonstrably flowing, carries no state, writes no event row, and exists to replace a guessed allowance with a measured one. If a step produces no output for `idle_timeout`, the beat correctly stops.
- **No new sweeper.** Idle expiry rides the existing lazy path; option 2 remains deferred as it is today.
- **No total-cap on the LLM stream.** `max_duration` exists in Symfony HttpClient but we manage the deadline ourselves for a clean error surface, consistent with `mcp-progress-plan.md` §2.
- **No retry-on-truncation.** Truncation is a terminal outcome for a step, not a transient failure. Retries stay out of scope (SPEC.md §Failure Semantics).

---

## 4. Schema

```php
#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?DateTimeImmutable $idleExpiresAt = null;
```

Migration alongside the newest existing one (`Version20260916100000.php`); plain nullable add, no backfill. Cleared in `TaskWorkflowService::resetForRun()` and `SchedulerService::resetStepsForNextRun()` exactly where `expiresAt` already is. `SeedCommand` stamps it for realism (it currently backdates `expires_at` by hand at three sites).

---

## 5. Phases

### Phase 1 — Controller clocks (self-contained, testable without a worker)

1. `Step.idleExpiresAt` + migration; reset paths; seed.
2. `TASKWEAVER_STEP_IDLE_TIMEOUT` / `TASKWEAVER_STEP_GRACE` in `config/services.yaml` + boot-time validation.
3. `TaskWorkflowService`: stamp `idle_expires_at` at `markStepRunning`; add `touchActivity(Step)` and call it from every event-writing site (§3.1).
4. `Step::hasExpiredE2e()` / `hasExpiredIdle()` / `isStale()`; `Event::hasValidKey()` → `isStale()`.
5. `StepRepository`: extend both selection queries; `findStaleRunning()` returns a reason.
6. `expireStaleSteps()`: accurate reason per clock.
7. `budget` block on the status response (§3.3).
8. **§3.7**: gate `completeStep()` + status transitions on `isStale()` → `409`.
9. **§3.5a**: `POST /api/worker/progress/{taskId}/{stepId}` — refresh idle deadline, no event row.

### Phase 2 — Worker cooperation ✓ implemented

1. `ControllerClient`: read `budget` from the status response; pass it to the run loop. ✓ (returns the response; `StepBudget` parses it)
2. `LlmClient`: accept a deadline + idle threshold; abort the stream on either; return partial content with `truncated` + `reason` (reusing the `postWithRetry` salvage path). ✓ (`setIdleAbortSeconds()` / `setE2eAbortAt()`, `LlmAbortException`)
3. `RunCommand`: arm both clocks at `markRunning`; check at the top of each round and before each tool call; `complete(partial: true, reason: …)` on abort. ✓
4. Provision config: issue `step_idle_timeout` + `step_grace` (and finally consume `step_timeout`). ✓

The stall signal turned out to be simpler than planned: rather than timing gaps
ourselves, the idle window is pushed down as the request's `timeout` option, and
Symfony's own `isTimeout()` chunk *is* the signal. That is exact, needs no
polling, and is deterministically testable with `MockResponse` ("yielding an
empty string simulates an idle timeout").

### Phase 3 — Surfacing

1. Admin task/step detail: show both deadlines and the idle-since derivation. *(pending)*
2. Final-step envelope: carry `partial: true` / `reason` on the entry so the final step knows an input is incomplete. ✓ (step.partial → fetch payload → envelope `partial` + `note`)
3. Docs: SPEC.md §Configuration + §Stale Step Expiry + decisions log; WORKER.md §9 + decision #4; `.env.example` / `.env.dev` / `.env.test`. *(env done; SPEC/WORKER pending)*

### Phase 4 — Verification

1. Extend `dev/mock-llm.php` (or add `dev/mock-llm-stall.php`) with a mode that emits N tokens then stalls indefinitely — the e2e rig for the "1m55s of silence" case.
2. Manual e2e: seeded task + stalling fake → worker aborts at ~`idle − grace`, completes partial; controller timeline shows `step_completed` with `partial: true`.

---

## 6. Testing

**Controller (PHPUnit)**

- [ ] `markStepRunning` stamps **both** deadlines; `idle_expires_at − now == idle_timeout`.
- [ ] Activity (`registerEvent`, tool call, internal tool log) refreshes `idle_expires_at` but **never** `expires_at`.
- [ ] `registerEvent` refreshes the idle clock.
- [ ] **Progress beat** refreshes `idle_expires_at`, writes **no** `event` row, and returns the refreshed deadline.
- [ ] A progress beat for a step past either deadline → `409` (no resurrection of a stale step).
- [ ] **A stale step refuses ALL activity** (§3.9a): `touchActivity()` is a no-op, `recordProgress()` throws, and the internal-tool route 401s.
- [ ] A denied tool call does **not** roll the clock (rejection is not progress).
- [ ] Tool activity (internal tool log) rolls the clock — proves the `ToolProxyService → TaskWorkflowService` wiring is live, not silently `null`.
- [ ] A step idle-past-deadline is failed by `expireStaleSteps()` with the idle reason; an e2e-past step gets the e2e reason; both clocks agree when both have passed.
- [ ] Tool call with a passed idle deadline → **401** (extends the existing `hasValidKey` coverage).
- [ ] Status response carries a `budget` block with `e2e_remaining ≤ step_timeout`.
- [ ] **§3.7**: `complete` past either deadline → `409`; status PATCH likewise.
- [ ] Boot guard rejects `grace ≥ idle_timeout` and `idle_timeout ≥ step_timeout`.
- [ ] Selection query treats idle-stale as claimable (both `findClaimable` and the conversation variant).
- [ ] Regression: a *healthy* long step (activity every `idle/2`) is never flagged.

**Worker (PHPUnit)**

- [ ] `LlmClient`: deadline passed mid-stream → `cancel()` + partial content returned with `truncated: true`.
- [ ] `LlmClient`: silence past the idle threshold (driven by `MockHttpClient` idle chunks) → same graceful return.
- [ ] `LlmClient`: healthy stream is untouched (no false positive).
- [ ] `RunCommand`: budget exhausted before round N → loop breaks, `complete` called with `partial: true` + reason, no further tool calls.
- [ ] Precedence: CLI flag > env var > provision config > default (extends existing `resolveVar` coverage).
- [ ] Missing `budget` block (old controller) → falls back to provision config, then defaults; never crashes.

**Style/CI**

- [ ] `php-cs-fixer` clean (controller + worker); full controller suite + worker suite green.

---

## 7. Risks

1. **`llm_allowance` sizing.** Too small and the controller backstop false-positives on a legitimately long direct-mode generation; too large and the backstop is toothless. Derive it from the worker's LLM request timeout rather than a new magic number, and document the coupling.
2. **Lazy expiry latency, unchanged.** With one busy worker, a stale step is not swept until that worker next claims. The UI can show `running` past the deadline — pre-existing and by design, but more visible now that there are two deadlines. Option 2 (sweeper) is the real fix if it becomes annoying.
3. **Truncated-then-completed steps in the UI.** A partial step looks like a success unless surfaced. Phase 3 must land or the feature is misleading.
4. **Idle threshold vs. slow tools.** A single tool call slower than `idle_timeout` would look like a stall *to the worker*, which cannot see that the controller is still waiting on MCP. Mitigation: the controller refreshes `tool_requested`, and the worker's idle clock should be suspended while a tool call is in flight (the tool call's own timeout polices it). Worth calling out explicitly in implementation.

---

## 8. Open questions

1. **Defaults: `idle = 120`, `grace = 15`?**
   Recommendation: **yes**, with the boot guard making misconfiguration loud. 120s is comfortably longer than a healthy inter-round gap and far shorter than e2e. — *Confirm.*
2. **Idle activity granularity: every streamed LLM chunk, or only completed events?**
   **Decided: chunks count**, via a *throttled progress beat* (§3.5a) — no per-chunk HTTP. The point is to detect a *stall*; flowing tokens are proof of life, and the e2e clock stays the hard bound for a slow-but-alive model.
3. **Partial results as `completed` + flag rather than `failed`?** (§3.5)
   Recommendation: **`completed` + `partial`** — failure semantics discard the result, which defeats the purpose. — *Confirm.*
4. **Fix the `complete`/status deadline gap here (§3.7), or separately?**
   Recommendation: **here** — ~5 lines plus tests, and the idle clock is not real without it. — *Confirm.*
5. **Should the worker's abort reason feed the final-step envelope as a `partial` marker, or stay audit-only?**
   Recommendation: **feed the envelope** — the consumer is the thing best positioned to decide what a truncated input means. — *Confirm.*
