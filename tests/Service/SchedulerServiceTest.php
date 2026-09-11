<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Step;
use App\Entity\Task;
use App\Service\SchedulerService;
use App\Service\TaskWorkflowService;
use App\Service\TimezoneService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Scheduler + recurring-task lifecycle semantics.
 *
 * These are the invariants the operator cares about:
 *   1. Enabling a schedule stamps an immediate concrete "Next Run".
 *   2. A due recurring task is marked ready and its cursor advances.
 *   3. A delayed tick / brief outage catches up a passed slot instead of
 *      skipping to the next occurrence.
 *   4. Completing a recurring task keeps it scheduled (next run advanced);
 *      completing a one-shot task clears the cursor.
 *   5. Failing a recurring task keeps it scheduled (so an outage/failure
 *      doesn't permanently stall it).
 */
class SchedulerServiceTest extends TestCase
{
    private TimezoneService $tz;

    protected function setUp(): void
    {
        $this->tz = new TimezoneService('UTC');
    }

    private function scheduler(?EntityManagerInterface $em = null): SchedulerService
    {
        return new SchedulerService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            // TaskRepository is unused by isDue/nextRunAt; stub it loosely.
            $this->createStub(\App\Repository\TaskRepository::class),
            $this->tz,
        );
    }

    private function recurringTask(string $schedule, ?DateTimeImmutable $nextRun = null): Task
    {
        $task = new Task('Recurring', 'desc');
        $task->setSchedule($schedule);
        $task->setNextRunAt($nextRun);

        return $task;
    }

    // ── 1. isDue semantics ────────────────────────────────────────────────

    public function testOneOffTaskIsNeverDue(): void
    {
        $task = new Task('one-shot', '');
        $task->setNextRunAt(null);
        self::assertFalse($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-07 10:00:00', new DateTimeZone('UTC'))));
    }

    public function testCursoredTaskIsDueAtItsSlot(): void
    {
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        self::assertTrue($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC'))));
    }

    public function testCursoredTaskIsDueWhenSlotPassesDuringOutage(): void
    {
        // Scheduler was down at 08:00; it comes back at 08:14. The 08:00 slot
        // never ran, so the task must still be considered due (catch-up),
        // not pushed to the next occurrence.
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        self::assertTrue($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-08 08:14:00', new DateTimeZone('UTC'))));
    }

    public function testCursoredTaskNotDueBeforeItsSlot(): void
    {
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        self::assertFalse($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-08 07:59:00', new DateTimeZone('UTC'))));
    }

    public function testCursoredTaskNotDueAfterCursorAdvancedPastNow(): void
    {
        // Task already marked ready earlier and cursor advanced to tomorrow;
        // it should not re-fire.
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-09 08:00:00', new DateTimeZone('UTC')));
        self::assertFalse($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-08 08:30:00', new DateTimeZone('UTC'))));
    }

    public function testNullCursorFallsBackToCronMinute(): void
    {
        // Legacy row: schedule set but no cursor. Only fires on the exact
        // cron minute (old behavior), never a false storm.
        $task = $this->recurringTask('0 8 * * 1-5', null);
        self::assertTrue($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC'))));
        self::assertFalse($this->scheduler()->isDue($task, new DateTimeImmutable('2026-09-08 08:01:00', new DateTimeZone('UTC'))));
    }

    // ── 2. nextRunAt computation ──────────────────────────────────────────

    public function testNextRunAdvancesToNextOccurrence(): void
    {
        $task = $this->recurringTask('0 8 * * 1-5', null);
        $next = $this->scheduler()->nextRunAt($task, new DateTimeImmutable('2026-09-07 10:00:00', new DateTimeZone('UTC')));
        // Mon 10:00 -> Tue 08:00 (Mon's 08:00 already passed).
        self::assertSame('2026-09-08 08:00:00', $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    // ── 3+4+5. Workflow: recurring keeps cursor on complete AND fail ─────

    /**
     * EntityManager mock whose getRepository(Event) returns an object whose
     * revokeKeysForStep() is a no-op.
     */
    private function emWithEventRepo(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(\App\Repository\EventRepository::class);
        $repo->method('revokeKeysForStep')->willReturn(0);
        $em->method('getRepository')->willReturn($repo);

        return $em;
    }

    private function workflow(): TaskWorkflowService
    {
        return new TaskWorkflowService(
            $this->emWithEventRepo(),
            new NullLogger(),
            $this->scheduler(),
            new \App\Service\ConversationService(
                $this->emWithEventRepo(),
                $this->createStub(\App\Repository\MessageRepository::class),
                new TimezoneService('UTC'),
                new NullLogger(),
            ),
            600,
        );
    }

    public function testCompletingRecurringTaskAdvancesNextRun(): void
    {
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        $step = new Step('final', 'done');
        $step->setIsFinal(true);
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $worker = new \App\Entity\Worker('w', ['x']);

        // Complete the final step.
        $this->workflow()->completeStep($step, $worker, ['ok' => true]);

        // Recurring: status completed but next run is still set (future).
        self::assertSame(Task::STATUS_COMPLETED, $task->getStatus());
        self::assertNotNull($task->getNextRunAt());
        self::assertGreaterThan(new DateTimeImmutable(), $task->getNextRunAt());
    }

    public function testFailingRecurringTaskKeepsNextRun(): void
    {
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        $step = new Step('final', 'fails');
        $step->setIsFinal(true);
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $worker = new \App\Entity\Worker('w', ['x']);

        $this->workflow()->failStep($step, $worker, 'downstream 503');

        self::assertSame(Task::STATUS_FAILED, $task->getStatus());
        self::assertNotNull($task->getNextRunAt());
        self::assertGreaterThan(new DateTimeImmutable(), $task->getNextRunAt());
    }

    public function testCompletingOneOffTaskClearsNextRun(): void
    {
        $task = new Task('one-shot', '');
        $task->setNextRunAt(new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        $step = new Step('final', 'done');
        $step->setIsFinal(true);
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $worker = new \App\Entity\Worker('w', ['x']);

        $this->workflow()->completeStep($step, $worker, ['ok' => true]);

        self::assertSame(Task::STATUS_COMPLETED, $task->getStatus());
        self::assertNull($task->getNextRunAt());
    }

    // ── 6. Tick resets steps for the next recurring run ──────────────────

    public function testTickResetsStepsForRecurringRun(): void
    {
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-07 08:00:00', new DateTimeZone('UTC')));
        $task->setStatus(Task::STATUS_COMPLETED);

        $step1 = new Step('s1', '');
        $step1->setIsFinal(false);
        $step1->setStatus(Step::STATUS_COMPLETED);
        $step1->setResult(['x' => 1]);
        $step1->setStartedAt(new DateTimeImmutable('2026-09-07 07:55:00', new DateTimeZone('UTC')));
        $step1->setFinishedAt(new DateTimeImmutable('2026-09-07 07:58:00', new DateTimeZone('UTC')));
        $task->addStep($step1);

        $stepF = new Step('final', '');
        $stepF->setIsFinal(true);
        $stepF->setStatus(Step::STATUS_COMPLETED);
        $task->addStep($stepF);

        // The scheduler mock: findDue is exercised via isDue (pure), and tick
        // calls reset + sets ready. Use an EM that tracks persists.
        $em = $this->emWithEventRepo();

        // FindDue runs a query builder — it will return [] on the mock, so we
        // can't test tick end-to-end through findDue here. Instead verify the
        // reset helper's effect via a due task passed to isDue + a manual
        // invocation of the private method using reflection.
        $scheduler = $this->scheduler($em);

        // The tick path is covered by the kernel-level completion test; here
        // we verify the reset semantics directly.
        $ref = new ReflectionMethod(SchedulerService::class, 'resetStepsForNextRun');
        $ref->setAccessible(true);
        $ref->invoke($scheduler, $task);

        self::assertSame(Step::STATUS_PENDING, $step1->getStatus(), 'completed non-final step reset to pending');
        self::assertNull($step1->getResult(), 'result cleared');
        self::assertNull($step1->getStartedAt(), 'started_at cleared');
        self::assertNull($step1->getFinishedAt(), 'finished_at cleared');
        self::assertSame(Step::STATUS_PENDING, $stepF->getStatus(), 'final step reset to pending');

        // A pending step is left untouched.
        $stepP = new Step('pending', '');
        $stepP->setIsFinal(false);
        $stepP->setStatus(Step::STATUS_PENDING);
        $task->addStep($stepP);
        $ref->invoke($scheduler, $task);
        self::assertSame(Step::STATUS_PENDING, $stepP->getStatus());
    }

    // ── 7. Admin "Run" action (resetForRun) ─────────────────────────────

    public function testRunResetsOneOffTaskForImmediatePickup(): void
    {
        // A failed one-off task, previously run (step in a terminal state).
        $task = new Task('one-off', '');
        $task->setStatus(Task::STATUS_FAILED);
        $step = new Step('do', '');
        $step->setIsFinal(true);
        $step->setStatus(Step::STATUS_FAILED);
        $step->setResult(['old' => 1]);
        $step->setStartedAt(new DateTimeImmutable('2026-09-06 10:00:00'));
        $step->setFinishedAt(new DateTimeImmutable('2026-09-06 10:05:00'));
        $task->addStep($step);

        $workflow = $this->workflow();
        $workflow->resetForRun($task);

        // One-off → ready, next_run = now (queued for next worker), step reset.
        self::assertSame(Task::STATUS_READY, $task->getStatus());
        self::assertNotNull($task->getNextRunAt(), 'one-off run queues with next_run = now');
        self::assertLessThanOrEqual(new DateTimeImmutable(), $task->getNextRunAt());
        self::assertSame(Step::STATUS_PENDING, $step->getStatus());
        self::assertNull($step->getResult());
        self::assertNull($step->getStartedAt());
        self::assertNull($step->getFinishedAt());
    }

    public function testRunKeepsRecurringTaskOnCadence(): void
    {
        // A completed recurring task — "Run now" should run immediately but
        // keep the upcoming scheduled cursor, not set it to now.
        $task = $this->recurringTask('0 8 * * 1-5', new DateTimeImmutable('2026-09-08 08:00:00', new DateTimeZone('UTC')));
        $task->setStatus(Task::STATUS_COMPLETED);
        $step = new Step('final', '');
        $step->setIsFinal(true);
        $step->setStatus(Step::STATUS_COMPLETED);
        $task->addStep($step);

        $this->workflow()->resetForRun($task);

        self::assertSame(Task::STATUS_READY, $task->getStatus());
        self::assertSame(Step::STATUS_PENDING, $step->getStatus());
        self::assertNotNull($task->getNextRunAt());
        // The cursor is the next scheduled occurrence, not "now" (so the tick
        // won't re-fire it every minute while waiting on a worker). Since the
        // test runs "now", the next occurrence is in the future.
        self::assertGreaterThan(new DateTimeImmutable(), $task->getNextRunAt());
    }

    public function testRunRejectsWhileStepIsRunning(): void
    {
        $task = new Task('in-flight', '');
        $task->setStatus(Task::STATUS_READY);
        $step = new Step('do', '');
        $step->setIsFinal(true);
        $step->setStatus(Step::STATUS_RUNNING);
        $task->addStep($step);

        $this->expectException(LogicException::class);
        $this->workflow()->resetForRun($task);
    }

    // ── Timezone awareness (SPEC.md → Configuration) ─────────────────────

    public function testNextRunIsInterpretedInTheDeploymentTimezone(): void
    {
        // A daily 08:00 schedule in America/New_York must produce a cursor
        // that IS 08:00 in that zone — not 08:00 UTC (which would be 04:00
        // New York during EDT, i.e. wrong by four hours).
        $tz = new TimezoneService('America/New_York');
        $scheduler = new SchedulerService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(\App\Repository\TaskRepository::class),
            $tz,
        );

        $task = $this->recurringTask('0 8 * * *', null);
        $now = new DateTimeImmutable('2026-09-07 10:00:00', new DateTimeZone('America/New_York'));
        $next = $scheduler->nextRunAt($task, $now);

        // Mon 10:00 NY → Tue 08:00 NY.
        self::assertSame('2026-09-08 08:00:00', $next->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i:s'));
    }

    public function testIsDueUsesTheDeploymentTimezoneForCron(): void
    {
        // Cron minute matching happens in the deployment zone (the scheduler
        // converts `now` to it before asking cron). An 08:00 NY cron must be
        // due at 08:00 in America/New_York, not at 08:00 UTC.
        $tz = new TimezoneService('America/New_York');
        $scheduler = new SchedulerService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(\App\Repository\TaskRepository::class),
            $tz,
        );

        $task = $this->recurringTask('0 8 * * *', null);
        $ny = new DateTimeZone('America/New_York');

        // 07:59 in the deployment zone → not due; 08:00 → due.
        self::assertFalse($scheduler->isDue($task, new DateTimeImmutable('2026-09-08 07:59:00', $ny)));
        self::assertTrue($scheduler->isDue($task, new DateTimeImmutable('2026-09-08 08:00:00', $ny)));
    }
}
