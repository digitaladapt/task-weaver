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
        if (null === $step || $step->getTask()->getId()->toRfc4122() !== $taskId) {
            return $this->json(['error' => 'Step not found for this task'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : '';

        // Only `running` is an allowed forward transition here; `failed` is
        // handled via the failure path. A step already expired is rejected.
        if (Step::STATUS_RUNNING !== $status) {
            return $this->json(['error' => 'Only status "running" is accepted here'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $workflow->markStepRunning($step, $worker);
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
