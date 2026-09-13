<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Step;

use function array_diff;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function in_array;

/**
 * @extends ServiceEntityRepository<Step>
 */
class StepRepository extends ServiceEntityRepository
{
    /**
     * The step tags that are actual sandbox capabilities for a worker.
     *
     * External-tool tags (tags carried by live ToolDefs) are proxied by
     * TaskWeaver for ANY worker, so they are subtracted here: the worker
     * never needs to carry `weather` or `echo` to claim a step that uses
     * those tools (SPEC.md → Claiming & Matching, Tool Wrangling).
     *
     * @param string[] $stepTags
     * @param string[] $externalTags tags of live (non-removed, server enabled) ToolDefs
     *
     * @return string[]
     */
    public static function requiredCapabilityTags(array $stepTags, array $externalTags): array
    {
        return array_values(array_diff($stepTags, $externalTags));
    }

    /**
     * Whether a worker can claim a step: its tags must cover the step's
     * SANDBOX capability tags (worker tags ⊇ required capability tags).
     * External-tool tags on the step are never a requirement — the
     * controller's tool proxy covers them.
     *
     * @param string[] $workerTags
     * @param string[] $stepTags
     * @param string[] $externalTags
     */
    public static function workerCoversStep(array $workerTags, array $stepTags, array $externalTags): bool
    {
        return [] === array_diff(self::requiredCapabilityTags($stepTags, $externalTags), $workerTags);
    }

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
     * Claim matching is capability-based (SPEC.md → Claiming & Matching):
     * the worker must cover the step's SANDBOX capability tags (terminal,
     * php, node, …) — anything the worker would run locally. Tags that name
     * EXTERNAL tools (weather, echo, commands, …) are proxied by TaskWeaver
     * for any worker, so they are never a claim requirement.
     *
     * @param string[] $workerTags
     */
    public function findClaimable(array $workerTags, DateTimeImmutable $now): ?Step
    {
        // Live external-tool tags: every tag carried by a non-removed ToolDef
        // on an enabled server. TaskWeaver can proxy these for any worker.
        $externalTags = $this->getEntityManager()
            ->getRepository(\App\Entity\ToolDef::class)
            ->findLiveTagNames();

        // Load all pending + stale-running steps; tag matching is done in PHP.
        $candidates = $this->createQueryBuilder('s')
            ->select('s', 't')
            ->join('s.task', 't')
            ->where('s.status = :pending OR (s.status = :running AND s.expiresAt < :now)')
            ->andWhere('t.status = :taskStatus')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.conversationId IS NULL')
            ->andWhere('t.compactionConversationId IS NULL')
            ->setParameter('pending', Step::STATUS_PENDING)
            ->setParameter('running', Step::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->setParameter('taskStatus', \App\Entity\Task::STATUS_READY)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('s.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $step) {
            // 1. Worker capability check — the step's SANDBOX tags (step tags
            //    minus live external-tool tags) must be ⊆ worker tags. The
            //    external tool tags themselves are covered by TaskWeaver's
            //    proxy, not the worker (SPEC.md → Tool Wrangling via Tags).
            if (!self::workerCoversStep($workerTags, $step->getTags(), $externalTags)) {
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
     * Find the next eligible reply-task step for a worker (conversations
     * priority — docs/conversations-plan.md §5.2, D6).
     *
     * Same capability matching as normal steps: the worker must cover the
     * step's SANDBOX capability tags; external-tool tags are proxied.
     * Reply tasks have exactly one pending final step. Order: oldest
     * conversation first (by task creation — FIFO for replies).
     *
     * @param string[] $workerTags
     */
    public function findClaimableByConversation(array $workerTags, DateTimeImmutable $now): ?Step
    {
        $externalTags = $this->getEntityManager()
            ->getRepository(\App\Entity\ToolDef::class)
            ->findLiveTagNames();

        $candidates = $this->createQueryBuilder('s')
            ->select('s', 't')
            ->join('s.task', 't')
            ->where('s.status = :pending OR (s.status = :running AND s.expiresAt < :now)')
            ->andWhere('t.status = :taskStatus')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.conversationId IS NOT NULL OR t.compactionConversationId IS NOT NULL')
            ->setParameter('pending', Step::STATUS_PENDING)
            ->setParameter('running', Step::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->setParameter('taskStatus', \App\Entity\Task::STATUS_READY)
            ->orderBy('t.createdAt', 'ASC')
            ->addOrderBy('s.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $step) {
            if (!self::workerCoversStep($workerTags, $step->getTags(), $externalTags)) {
                continue;
            }
            // Reply tasks are single-final-step; the step must be pending
            // (or stale-running to be failed then retried).
            if (Step::STATUS_RUNNING === $step->getStatus()) {
                continue;
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
            ->join('s.task', 't')
            ->where('s.status = :running')
            ->andWhere('s.expiresAt < :now')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('running', Step::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
