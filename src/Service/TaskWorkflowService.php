<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;

use function array_map;
use function count;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;

use LogicException;
use Psr\Log\LoggerInterface;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Central orchestration for task/step lifecycle on the controller:
 *   - marking steps running (stamps expires_at)
 *   - completing steps (revokes event keys, resolves the flow shape)
 *   - failing steps / lazy expiry of stale-running steps
 */
final class TaskWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly SchedulerService $scheduler,
        private readonly ConversationService $conversations,
        private readonly int $stepTimeout = 600,
        private readonly int $idleTimeout = 120,
        private readonly int $grace = 15,
    ) {
    }

    /**
     * Make a task claimable for a fresh run.
     *
     * Resets every step to `pending` (clearing started/finished/expiry
     * timestamps and results) and marks the task `ready`. Used by the admin
     * "Run" action (one-off tasks) and as the per-occurrence reset for
     * recurring tasks.
     *
     * @throws LogicException when any step is currently `running` (you may not
     *                        re-run a task that is actively being worked on)
     */
    public function resetForRun(Task $task): void
    {
        $now = new DateTimeImmutable();

        foreach ($task->getSteps() as $step) {
            if (Step::STATUS_RUNNING === $step->getStatus()) {
                throw new LogicException(sprintf('Task %s cannot be re-run while step %s is running.', $task->getId()->toRfc4122(), $step->getId()->toRfc4122()));
            }

            if (Step::STATUS_PENDING === $step->getStatus()) {
                continue;
            }
            $step->setStatus(Step::STATUS_PENDING);
            $step->setStartedAt(null);
            $step->setFinishedAt(null);
            $step->setExpiresAt(null);
            $step->setIdleExpiresAt(null);
            $step->setResult(null);
            $step->setRunId(null);
            $this->em->persist($step);
        }

        $task->setStatus(Task::STATUS_READY);
        if (null !== $task->getSchedule()) {
            // Recurring: run now but stay on cadence — the cursor is the next
            // scheduled occurrence, not "now", so the tick won't re-fire it
            // (and re-reset a running step) every minute while it's waiting
            // for a worker.
            $task->setNextRunAt($this->scheduler->nextRunAt($task, $now));
        } else {
            // One-off: "next run is now" — queued for the next available
            // worker. The scheduler ignores null-schedule tasks, so this is
            // never re-triggered by a tick.
            $task->setNextRunAt($now);
        }
        $task->touch();
        $this->em->persist($task);
    }

    /**
     * Mark a pending step running. The optional worker-reported $model is
     * the model that will actually execute this step (per-step selection or
     * the worker's resolved default) — recorded on the step_started event
     * payload for provenance (M5b; docs/model-selection-plan.md §5).
     */
    public function markStepRunning(Step $step, Worker $worker, ?string $model = null): void
    {
        if (Step::STATUS_PENDING !== $step->getStatus()) {
            // Only a pending step may transition to running. A stale-running
            // step must first be failed (see expireStaleSteps).
            throw new LogicException(sprintf('Step %s cannot start: status is %s, expected pending.', $step->getId()->toRfc4122(), $step->getStatus()));
        }

        $step->setStatus(Step::STATUS_RUNNING);
        $step->setStartedAt(new DateTimeImmutable());
        // Two clocks (docs/step-liveness-plan.md §3.1): the e2e budget is
        // stamped once and never refreshed; the idle budget is rolled forward
        // by every activity via touchActivity().
        $step->setExpiresAt((new DateTimeImmutable())->modify(sprintf('+%d seconds', $this->stepTimeout)));
        $step->setIdleExpiresAt((new DateTimeImmutable())->modify(sprintf('+%d seconds', $this->idleTimeout)));
        // Mint the run id BEFORE the step_started event is logged (and before
        // the first llm_call is registered) so EVERY event of this execution
        // — including step_started — carries it (docs/conversations-plan.md §4).
        $step->setRunId((string) Uuid::v4());

        $this->em->persist($step);
        $this->em->flush();

        $payload = ['expires_at' => $step->getExpiresAt()?->format('c')];
        if (null !== $step->getIdleExpiresAt()) {
            $payload['idle_expires_at'] = $step->getIdleExpiresAt()->format('c');
        }
        if (null !== $model && '' !== $model) {
            $payload['model'] = $model;
        }
        $this->log(Event::TYPE_STEP_STARTED, $step, $worker, $payload);
        // Flush now: the event must survive the request even though nothing
        // else down this code path persists afterwards (the worker's status
        // PATCH ends here). Same contract as completeStep()/failStep().
        $this->em->flush();
        $this->logger->info('Step started', ['step' => $step->getId()->toRfc4122()]);
    }

    /**
     * Register an event for a step. Returns the event entity with a fresh
     * event-scoped API key.
     */
    public function registerEvent(Step $step, Worker $worker): Event
    {
        if (Step::STATUS_RUNNING !== $step->getStatus()) {
            throw new LogicException(sprintf('Cannot register event for step %s: status is %s, expected running.', $step->getId()->toRfc4122(), $step->getStatus()));
        }

        $event = new Event($step, $step->getTask(), $worker, Event::TYPE_LLM_CALL);
        $event->setApiKey(KeyGenerator::generate());
        $event->setRunId($step->getRunId());
        $this->em->persist($event);
        $this->touchActivity($step);
        $this->em->flush();

        return $event;
    }

    /**
     * Roll the step's IDLE deadline forward: now + idle-timeout.
     *
     * The invariant (docs/step-liveness-plan.md §3.1): any activity on a
     * running step refreshes the idle clock. It NEVER touches `expires_at` —
     * the end-to-end budget is absolute and cannot be extended by activity.
     *
     * Two cases do NOT roll the clock:
     *  - a non-running step — completing/failing is terminal, and a stray
     *    event must not resurrect a deadline it no longer has;
     *  - an already-STALE step — its fate is sealed. Without this, a zombie
     *    worker could keep any event-writing route alive (e.g. internal tool
     *    logging) and roll its own idle clock forward forever, dodging the
     *    very reaping the clock exists to trigger. Freezing on staleness is
     *    what keeps the deadline authoritative rather than self-serve.
     *
     * Callers persist (they are always already mid-flush).
     */
    public function touchActivity(Step $step): void
    {
        if (Step::STATUS_RUNNING !== $step->getStatus()) {
            return;
        }

        if ($step->isStale(new DateTimeImmutable())) {
            return;
        }

        $step->setIdleExpiresAt((new DateTimeImmutable())->modify(sprintf('+%d seconds', $this->idleTimeout)));
        $this->em->persist($step);
    }

    /**
     * Record a worker progress beat: proof of life while the worker is
     * receiving a streamed LLM response (docs/step-liveness-plan.md §3.5a).
     *
     * Deliberately writes NO event row — a beat is a clock refresh, not an
     * observation; logging one per beat would flood the audit trail for no
     * informational gain. Returns the refreshed deadline so the worker can
     * re-arm its local clock from the authoritative value.
     *
     * @throws LogicException when the step is not running (terminal) — the
     *                        caller maps this to 409 so the worker abandons
     */
    public function recordProgress(Step $step): void
    {
        if (Step::STATUS_RUNNING !== $step->getStatus()) {
            throw new LogicException(sprintf('Cannot record progress for step %s: status is %s, expected running.', $step->getId()->toRfc4122(), $step->getStatus()));
        }

        if ($step->isStale(new DateTimeImmutable())) {
            throw new LogicException(sprintf('Cannot record progress for step %s: it has expired (%s).', $step->getId()->toRfc4122(), $step->staleReason(new DateTimeImmutable())));
        }

        $this->touchActivity($step);
        $this->em->flush();
    }

    /**
     * The step's remaining budgets, for the worker (docs/step-liveness-plan.md §3.3).
     *
     * Durations, not timestamps: worker and controller are separate
     * containers with independent wall clocks, so "you have N seconds left"
     * survives skew where an absolute deadline would not. `e2e_remaining` is
     * derived from the stamped deadline, so it absorbs any delay between
     * stamping and the worker receiving it.
     *
     * @return array{e2e_remaining: int, idle_timeout: int, grace: int}
     */
    public function budgetFor(Step $step): array
    {
        $now = new DateTimeImmutable();
        $remaining = 0;
        if (null !== $step->getExpiresAt()) {
            $remaining = max(0, $step->getExpiresAt()->getTimestamp() - $now->getTimestamp());
        }

        return [
            'e2e_remaining' => $remaining,
            'idle_timeout' => $this->idleTimeout,
            // The head start the worker should take on both clocks, so it
            // aborts in time to still transmit partial results.
            'grace' => $this->grace,
        ];
    }

    /**
     * Submit a step's result. Revokes every event key for the step and marks
     * it completed, then resolves the task shape:
     *   - when this was a non-final step of a multi-step task, makes the
     *     final step claimable if all siblings are done (task → ready);
     *   - when this was the final step, marks the task completed.
     */
    public function completeStep(Step $step, Worker $worker, array $result): void
    {
        if (Step::STATUS_RUNNING !== $step->getStatus()) {
            throw new LogicException(sprintf('Cannot complete step %s: status is %s, expected running.', $step->getId()->toRfc4122(), $step->getStatus()));
        }

        // §3.7: a step past EITHER deadline is already decided server-side.
        // Accepting its result would let a zombie worker write after the
        // controller declared it stale, making the idle clock advisory
        // instead of real (SPEC.md → Stale Step Expiry: denial spans
        // "any rejected tool call, status transition, or complete").
        $now = new DateTimeImmutable();
        if ($step->isStale($now)) {
            throw new LogicException(sprintf('Cannot complete step %s: it has expired (%s).', $step->getId()->toRfc4122(), $step->staleReason($now)));
        }

        // Revoke every event key for this step (success or failure).
        $this->em->getRepository(Event::class)->revokeKeysForStep($step->getId()->toRfc4122());

        $step->setResult($result);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setFinishedAt(new DateTimeImmutable());
        $this->em->persist($step);

        $this->log(Event::TYPE_STEP_COMPLETED, $step, $worker, ['result' => $result]);

        $task = $step->getTask();

        if ($step->isFinal()) {
            $task->setStatus(Task::STATUS_COMPLETED);
            // A recurring task stays scheduled: advance the cursor to the
            // next occurrence so the next run is picked up by a worker and a
            // visible "Next Run" is always shown. A one-shot task is done —
            // clear the cursor (it never runs on its own again).
            $this->applyScheduleCursor($task, Task::STATUS_COMPLETED);
            $task->touch();
            $this->em->persist($task);
            $this->logger->info('Task completed', ['task' => $task->getId()->toRfc4122()]);
        } else {
            // Non-final step done: if every non-final sibling is finished,
            // the final step becomes eligible (task → ready for claim).
            $this->resolveNonFinalCompletion($task);
        }

        $this->em->flush();

        // A transient reply task reached a terminal state → materialize the
        // reply and hard-delete the run (docs/conversations-plan.md §5.1/D10).
        // Called here (not only from the controller) so every path — worker
        // API, scheduler expiry, tests — gets the same cleanup.
        $this->conversations->onStepTerminal($task, $step);
    }

    /**
     * After a final step finishes (completed or failed), keep a recurring task
     * on its schedule by advancing next_run_at to the next occurrence, or
     * clear it for a one-shot task.
     */
    private function applyScheduleCursor(Task $task, string $endStatus): void
    {
        if (null === $task->getSchedule()) {
            $task->setNextRunAt(null);

            return;
        }

        // Recurring: next run is the next cron occurrence after now. The
        // scheduler will promote the task back to ready when that time is due.
        $task->setNextRunAt(
            $this->scheduler->nextRunAt($task, new DateTimeImmutable())
        );
    }

    /**
     * After a non-final step completes, check whether all its siblings are
     * done — if so, transition the task so the final step becomes claimable.
     */
    private function resolveNonFinalCompletion(Task $task): void
    {
        $nonFinal = $task->getSteps()->filter(static fn (Step $s) => !$s->isFinal());
        $allDone = $nonFinal->count() > 0
            && $nonFinal->forAll(static fn (int $_, Step $s) => Step::STATUS_COMPLETED === $s->getStatus());

        if ($allDone) {
            $task->setStatus(Task::STATUS_READY);
            $task->touch();
            $this->em->persist($task);
            $this->logger->info('All non-final steps done; final step eligible', ['task' => $task->getId()->toRfc4122()]);
        }
    }

    /**
     * Fail a step (worker-reported failure or lazy expiry).
     * Event keys are revoked here too. Shape resolution proceeds like a failure.
     */
    public function failStep(Step $step, Worker $worker, string $reason, string $eventType = Event::TYPE_STEP_FAILED): void
    {
        if (!in_array($step->getStatus(), [Step::STATUS_RUNNING, Step::STATUS_PENDING], true)) {
            throw new LogicException(sprintf('Cannot fail step %s: status is %s.', $step->getId()->toRfc4122(), $step->getStatus()));
        }

        // NOTE: no stale guard here, deliberately. This method is the
        // terminal state transition for lazy expiry too (expireStaleSteps()
        // calls it with an already-stale step), so a deadline check would
        // make expiry unable to do its job. Worker-reported failures that
        // arrive late are harmless: the step is already failed either way.

        $this->em->getRepository(Event::class)->revokeKeysForStep($step->getId()->toRfc4122());

        $step->setStatus(Step::STATUS_FAILED);
        $step->setFinishedAt(new DateTimeImmutable());
        $step->setResult(null);
        $this->em->persist($step);

        $this->log($eventType, $step, $worker, ['reason' => $reason]);

        $task = $step->getTask();

        if ($step->isFinal()) {
            $task->setStatus(Task::STATUS_FAILED);
            // Same scheduling rule as completion: a recurring task keeps its
            // next run so the outage/failure doesn't permanently stall it.
            $this->applyScheduleCursor($task, Task::STATUS_FAILED);
            $task->touch();
            $this->em->persist($task);
        } else {
            // A non-final step failing still unblocks the final step (failure
            // data flows forward). So we resolve the shape like a completion.
            $this->resolveNonFinalCompletion($task);
        }

        $this->em->flush();
        $this->logger->info('Step failed', ['step' => $step->getId()->toRfc4122(), 'reason' => $reason]);

        // Reply-run failure → message failed + run hard-deleted (D10).
        $this->conversations->onStepTerminal($task, $step, true, $reason);
    }

    /**
     * Lazy expiry: mark every stale `running` step as failed with a reason
     * naming the clock that tripped — the end-to-end deadline or the rolling
     * idle deadline (docs/step-liveness-plan.md §3.6). Called on the way to
     * doing other work (e.g. from the scheduler / claim path).
     */
    public function expireStaleSteps(): void
    {
        $now = new DateTimeImmutable();
        $staleIds = array_map(
            static fn (Step $s): string => $s->getId()->toRfc4122(),
            $this->em->getRepository(Step::class)->findStaleRunning($now),
        );

        foreach ($staleIds as $stepId) {
            // Re-read each iteration: failing a reply step hard-deletes its
            // run and clears the UnitOfWork, detaching earlier fetches.
            $step = $this->em->getRepository(Step::class)->find($stepId);
            if (null === $step) {
                continue; // already cleaned up (conversation run deleted)
            }

            $worker = $step->getEvents()->isEmpty()
                ? null
                : $step->getEvents()->last()->getWorker();

            if ($worker instanceof Worker) {
                // failStep() already flows the failure into onStepTerminal()
                // for reply tasks (materialize failure + hard-delete).
                // staleReason() names the clock that tripped so the audit
                // trail distinguishes an absolute-deadline hit from a stall.
                $this->failStep($step, $worker, 'worker timeout: '.$step->staleReason($now));
            } else {
                // No worker recorded — mark it failed directly; the failure
                // still flows into cleanup below. Flush before that cleanup:
                // it may clear the UnitOfWork, which would drop unflushed
                // changes to otherwise-unrelated steps.
                $step->setStatus(Step::STATUS_FAILED);
                $step->setFinishedAt(new DateTimeImmutable());
                $this->em->persist($step);
                $this->em->flush();
                $this->conversations->onStepTerminal($step->getTask(), $step, true, 'worker timeout');
            }
        }

        if (count($staleIds) > 0) {
            $this->em->flush();
        }
    }

    private function log(string $type, Step $step, Worker $worker, array $payload = []): void
    {
        $event = new Event($step, $step->getTask(), $worker, $type);
        $event->setPayload($payload);
        $event->setRunId($step->getRunId());
        $this->em->persist($event);
    }
}
