<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Step;
use App\Entity\Worker;
use App\Repository\StepRepository;
use App\Service\TaskWorkflowService;
use App\Service\WorkerAuthService;

use function is_string;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class StepController extends AbstractController
{
    #[Route('/step/{taskId}/{stepId}/status', name: 'worker_step_status', methods: ['PATCH'])]
    public function status(
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

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : '';
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : '';

        try {
            if (Step::STATUS_RUNNING === $status) {
                $workflow->markStepRunning($step, $worker);
            } elseif (Step::STATUS_FAILED === $status) {
                // A worker reporting its own failure (e.g. the LLM is
                // unreachable, or the step is unrecoverable). Better than
                // waiting for the lazy 600s expiry: the failure flows into
                // the final step's envelope immediately.
                $workflow->failStep($step, $worker, '' !== $reason ? $reason : 'reported failed by worker');
            } else {
                return $this->json(['error' => 'Only statuses "running" and "failed" are accepted here'], Response::HTTP_BAD_REQUEST);
            }
        } catch (LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'ok' => true,
            'step_id' => $step->getId()->toRfc4122(),
            'status' => $step->getStatus(),
            'expires_at' => $step->getExpiresAt()?->format('c'),
        ]);
    }
}
