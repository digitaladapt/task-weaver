<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Step;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function in_array;

/**
 * @extends ServiceEntityRepository<Step>
 */
class StepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Step::class);
    }

    /**
     * Find the next eligible step for a task with the given capability tags.
     *
     * Eligibility per SPEC.md (Stale Step Expiry):
     *   - a step that is `pending` and not yet started (the final step only
     *     becomes eligible once its non-final siblings are finished), or
     *   - a step stuck `running` past its `expires_at` (stale → handled by
     *     the caller by failing it then re-selecting).
     *
     * Claim matching is capability-based: a step is claimable by a worker if
     * the worker has ALL of the step's tags (worker tags ⊇ step tags).
     *
     * @param string[] $workerTags
     */
    public function findClaimable(array $workerTags, DateTimeImmutable $now): ?Step
    {
        // Load all pending + stale-running steps; tag matching is done in PHP.
        $candidates = $this->createQueryBuilder('s')
            ->select('s', 't')
            ->join('s.task', 't')
            ->where('s.status = :pending OR (s.status = :running AND s.expiresAt < :now)')
            ->andWhere('t.status = :taskStatus')
            ->setParameter('pending', Step::STATUS_PENDING)
            ->setParameter('running', Step::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->setParameter('taskStatus', \App\Entity\Task::STATUS_READY)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('s.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $step) {
            // 1. Worker must have every tag the step requires.
            $stepTags = $step->getTags();
            if ([] !== array_diff($stepTags, $workerTags)) {
                continue;
            }

            // 2. For multi-step tasks, a non-final step is only claimable while
            //    the final step hasn't started; the final step is claimable only
            //    once every non-final step is finished.
            $task = $step->getTask();
            $nonFinalPending = $task->getSteps()->filter(
                static fn (Step $s) => !$s->isFinal() && in_array($s->getStatus(), [Step::STATUS_PENDING, Step::STATUS_RUNNING], true)
            );

            if ($step->isFinal()) {
                if ($nonFinalPending->count() > 0) {
                    continue;
                }
            } else {
                $final = $task->getFinalStep();
                if (null !== $final && Step::STATUS_RUNNING === $final->getStatus()) {
                    continue;
                }
            }

            return $step;
        }

        return null;
    }

    /**
     * Find a stale-running step by expires_at, for lazy expiry.
     */
    public function findStaleRunning(DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :running')
            ->andWhere('s.expiresAt < :now')
            ->setParameter('running', Step::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
