<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Step;
use App\Entity\Task;
use App\Message\TaskDueMessage;
use App\Repository\TaskRepository;

use function count;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Task scheduler (SPEC.md → Scheduling).
 *
 * On each tick, finds due recurring tasks (cron expression + timezone) and
 * marks them `ready` for claim, recalculating next_run_at. One-shot tasks
 * stay `draft` until triggered via /tasks/{id}/run.
 */
final class SchedulerService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
        private readonly MessageBusInterface $bus,
        private readonly TimezoneService $timezone,
    ) {
    }

    public function tick(DateTimeImmutable $now): void
    {
        $due = $this->findDue($now);

        foreach ($due as $task) {
            // A recurring task that previously ran to completion or failure
            // needs its steps reset so the new run starts fresh (completed /
            // failed steps aren't claimable). Idempotent for a not-yet-started
            // task: pending steps just stay pending.
            $this->resetStepsForNextRun($task);

            $task->setStatus(Task::STATUS_READY);
            $task->setNextRunAt($this->nextRunAt($task, $now));
            $task->touch();
            $this->em->persist($task);

            $this->bus->dispatch(new TaskDueMessage($task->getId()->toRfc4122()));
        }

        if (count($due) > 0) {
            $this->em->flush();
        }
    }

    /**
     * Reset a recurring task's steps to pending for its next run.
     *
     * Only touches terminal / running steps (completed, failed) so a brand-new
     * task's already-pending steps are left alone. Started/finished timestamps
     * and results are cleared so the new run records fresh events; stale
     * expires_at is dropped.
     */
    private function resetStepsForNextRun(Task $task): void
    {
        foreach ($task->getSteps() as $step) {
            if (Step::STATUS_PENDING === $step->getStatus()) {
                continue;
            }
            $step->setStatus(Step::STATUS_PENDING);
            $step->setStartedAt(null);
            $step->setFinishedAt(null);
            $step->setExpiresAt(null);
            $step->setResult(null);
            $this->em->persist($step);
        }
    }

    /**
     * Compute the next scheduled run for a recurring task.
     *
     * Used both by the tick (to advance the cursor after a run comes due) and
     * by the task editor (so enabling a schedule immediately shows a concrete
     * "Next Run" instead of a blank until the next minute tick).
     */
    public function nextRunAt(Task $task, DateTimeImmutable $now): DateTimeImmutable
    {
        $cron = CronExpression::factory($task->getSchedule() ?? '');
        $tz = new DateTimeZone($this->timezone->resolve());
        $next = $cron->getNextRunDate($now->setTimezone($tz));

        return DateTimeImmutable::createFromInterface($next);
    }

    /**
     * @return Task[]
     */
    private function findDue(DateTimeImmutable $now): array
    {
        $candidates = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.schedule IS NOT NULL')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('statuses', [Task::STATUS_DRAFT, Task::STATUS_READY, Task::STATUS_COMPLETED, Task::STATUS_FAILED])
            ->andWhere('t.nextRunAt IS NULL OR t.nextRunAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return array_filter($candidates, fn (Task $task) => $this->isDue($task, $now));
    }

    /**
     * Decide whether a scheduled task's next run is due now.
     *
     * The cursor (next_run_at) is authoritative: a task is due when its
     * next_run_at has arrived — or already passed (a delayed tick or a brief
     * outage catches the slot up instead of skipping to the next day's
     * occurrence). Using $cron->isDue(now) alone would miss a delayed tick:
     * cron matches the exact minute, so an 08:00 cron checked at 08:01 is
     * "not due" even though the 08:00 slot never ran.
     *
     * When there is no cursor yet (a schedule set before the save-time cursor
     * fix, or a hand-edited row), fall back to the cron's own minute match.
     * Once the tick marks it ready it also stamps a cursor, so from then on
     * the task is cursor-managed.
     */
    public function isDue(Task $task, DateTimeImmutable $now): bool
    {
        if (null === $task->getSchedule()) {
            return false;
        }

        if (null !== $task->getNextRunAt()) {
            return $task->getNextRunAt() <= $now;
        }

        $tz = new DateTimeZone($this->timezone->resolve());
        $cron = CronExpression::factory($task->getSchedule() ?? '');

        return $cron->isDue($now->setTimezone($tz));
    }
}
