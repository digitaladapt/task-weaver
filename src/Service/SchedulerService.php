<?php

declare(strict_types=1);

namespace App\Service;

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
    ) {
    }

    public function tick(DateTimeImmutable $now): void
    {
        $due = $this->findDue($now);

        foreach ($due as $task) {
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

        return array_filter($candidates, static function (Task $task) use ($now): bool {
            $cron = CronExpression::factory($task->getSchedule() ?? '');
            $tz = new DateTimeZone($task->getTimezone());

            return $cron->isDue($now->setTimezone($tz));
        });
    }

    private function nextRunAt(Task $task, DateTimeImmutable $now): DateTimeImmutable
    {
        $cron = CronExpression::factory($task->getSchedule() ?? '');
        $tz = new DateTimeZone($task->getTimezone());
        $next = $cron->getNextRunDate($now->setTimezone($tz));

        return DateTimeImmutable::createFromInterface($next);
    }
}
