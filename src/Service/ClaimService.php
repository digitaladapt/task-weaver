<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;
use App\Repository\StepRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Claim matching: server-assigned, tags only.
 *
 * A worker claims the next capable task. "Capable" means the worker has ALL
 * of the step's tags (worker tags ⊇ step tags). Stale-running steps are
 * expired lazily on the way here. Returns { task, step } or null.
 */
final class ClaimService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StepRepository $steps,
        private readonly TaskWorkflowService $workflow,
    ) {
    }

    /**
     * Find the next claimable step for a worker.
     *
     * @return array{task: Task, step: Step}|null
     */
    public function claimFor(Worker $worker): ?array
    {
        $now = new DateTimeImmutable();

        // Lazy expiry: stale-running steps are failed on the way to doing
        // other work (SPEC.md → Stale Step Expiry).
        $this->workflow->expireStaleSteps();

        $step = $this->steps->findClaimable($worker->getTags(), $now);

        if (null === $step) {
            return null;
        }

        return ['task' => $step->getTask(), 'step' => $step];
    }
}
