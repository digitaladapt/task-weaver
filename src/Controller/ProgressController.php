<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Repository\StepRepository;
use App\Service\TaskWorkflowService;
use App\Service\WorkerAuthService;
use DateTimeImmutable;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Worker progress beats (docs/step-liveness-plan.md §3.5a).
 *
 * While the worker is receiving a streamed LLM response it periodically
 * reports "still working" for the step. Each beat rolls the step's rolling
 * IDLE deadline forward, so the controller can tell a healthy-but-slow
 * generation (chunks flowing → beats keep coming) from a dead worker
 * (beats stop → the idle clock lands).
 *
 * This is deliberately NOT a bare keepalive: it fires only while the worker
 * is demonstrably making progress, carries no state, and writes no event row
 * — a beat is a clock refresh, not an observation. The worker treats it as
 * best-effort: a failed beat is logged and ignored, never fatal to the step.
 */
#[Route('/api/worker')]
final class ProgressController extends AbstractController
{
    #[Route('/progress/{taskId}/{stepId}', name: 'worker_step_progress', methods: ['POST'])]
    public function progress(
        string $taskId,
        string $stepId,
        Request $request,
        WorkerAuthService $auth,
        StepRepository $steps,
        TaskWorkflowService $workflow,
    ): JsonResponse {
        $worker = $auth->findWorker($request->headers->get('Authorization'));
        if (!$worker instanceof Worker) {
            return $this->json(['error' => 'Invalid worker API key'], Response::HTTP_UNAUTHORIZED);
        }

        $step = $steps->find($stepId);
        if (null === $step
            || $step->getTask()->getId()->toRfc4122() !== $taskId
            || $step->getTask()->isDeleted()) {
            return $this->json(['error' => 'Step not found for this task'], Response::HTTP_NOT_FOUND);
        }

        // A beat is only meaningful for a step that is still alive. Once it
        // is stale, the deadline has passed — no beat may resurrect it.
        if ($step->isStale(new DateTimeImmutable())) {
            return $this->json(['error' => 'Step has expired'], Response::HTTP_CONFLICT);
        }

        try {
            $workflow->recordProgress($step);
        } catch (LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'ok' => true,
            'step_id' => $step->getId()->toRfc4122(),
            'idle_expires_at' => $step->getIdleExpiresAt()?->format('c'),
        ]);
    }
}
