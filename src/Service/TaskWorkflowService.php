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
     * @return array<string, mixed>|null the persisted step key (or null when generated here)
     */
    public function markStepRunning(Step $step, Worker $worker): void
    {
        if (Step::STATUS_PENDING !== $step->getStatus()) {
            // Only a pending step may transition to running. A stale-running
            // step must first be failed (see expireStaleSteps).
            throw new LogicException(sprintf('Step %s cannot start: status is %s, expected pending.', $step->getId()->toRfc4122(), $step->getStatus()));
        }

        $step->setStatus(Step::STATUS_RUNNING);
        $step->setStartedAt(new DateTimeImmutable());
        $step->setExpiresAt((new DateTimeImmutable())->modify(sprintf('+%d seconds', $this->stepTimeout)));
        // Mint the run id BEFORE the step_started event is logged (and before
        // the first llm_call is registered) so EVERY event of this execution
        // — including step_started — carries it (docs/conversations-plan.md §4).
        $step->setRunId((string) Uuid::v4());

        $this->em->persist($step);
        $this->em->flush();

        $this->log(Event::TYPE_STEP_STARTED, $step, $worker, ['expires_at' => $step->getExpiresAt()?->format('c')]);
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
        $this->em->flush();

        return $event;
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
     * Lazy expiry: mark every stale `running` step (expires_at < now) as
     * failed with a worker-timeout reason. Called on the way to doing other
     * work (e.g. from the scheduler / claim path).
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
                $this->failStep($step, $worker, 'worker timeout: step expired at '.$step->getExpiresAt()?->format('c'));
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
