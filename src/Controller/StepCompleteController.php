<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Repository\StepRepository;
use App\Service\TaskWorkflowService;
use App\Service\WorkerAuthService;

use function is_array;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class StepCompleteController extends AbstractController
{
    #[Route('/step/{taskId}/{stepId}/complete', name: 'worker_step_complete', methods: ['POST'])]
    public function complete(
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
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        try {
            $workflow->completeStep($step, $worker, $result);
        } catch (LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'ok' => true,
            'status' => 'completed',
            'task_status' => $step->getTask()->getStatus(),
        ]);
    }
}
