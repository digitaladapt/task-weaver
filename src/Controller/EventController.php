<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Repository\StepRepository;
use App\Service\TaskWorkflowService;
use App\Service\WorkerAuthService;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class EventController extends AbstractController
{
    #[Route('/event/{taskId}/{stepId}', name: 'worker_event_register', methods: ['POST'])]
    public function register(
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

        try {
            $event = $workflow->registerEvent($step, $worker);
        } catch (LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'event_id' => $event->getId()->toRfc4122(),
            'api_key' => $event->getApiKey(),
        ]);
    }
}
