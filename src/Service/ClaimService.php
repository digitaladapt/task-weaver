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
 * A worker claims the next capable task. "Capable" means the worker covers
 * the step's SANDBOX capability tags (worker tags ⊇ required capabilities).
 * External-tool tags (weather, echo, …) are proxied by TaskWeaver for any
 * worker, so they are never a claim requirement (SPEC.md → Claiming & Matching).
 * Stale-running steps are expired lazily on the way here.
 *
 * Two-pass claim (docs/conversations-plan.md §5.2, D6): conversation reply
 * tasks are claimed FIRST (unanswered messages are high-priority but don't
 * disrupt the task flow — pass 2 drains normally when pass 1 is empty).
 * Returns { task, step } or null.
 */
final class ClaimService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StepRepository $steps,
        private readonly TaskWorkflowService $workflow,
        private readonly ConversationService $conversations,
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
        // other work (SPEC.md → Stale Step Expiry). If a reply task's step
        // goes stale, the failure flows into onStepTerminal() cleanup.
        $this->workflow->expireStaleSteps();

        // Pass 1 — conversations first (safety net ensures a reply run exists
        // for each pending message, covering a crash between post and create).
        $this->conversations->ensureReplyRuns();
        $step = $this->steps->findClaimableByConversation($worker->getTags(), $now);
        if (null !== $step) {
            // Claim → the user message is `running` (docs/conversations-plan.md
            // §5.4). Done here so every claimer path gets the transition.
            $this->conversations->onClaimed($step->getTask());

            return ['task' => $step->getTask(), 'step' => $step];
        }

        // Pass 2 — normal tasks (conversation tasks are excluded from
        // findClaimable).
        $step = $this->steps->findClaimable($worker->getTags(), $now);
        if (null === $step) {
            return null;
        }

        return ['task' => $step->getTask(), 'step' => $step];
    }
}
