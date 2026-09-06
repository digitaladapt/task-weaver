<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Worker;
use App\Repository\TaskRepository;
use App\Service\ToolResolver;
use App\Service\WorkerAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/worker')]
final class TaskFetchController extends AbstractController
{
    #[Route('/task/{taskId}', name: 'worker_task_fetch', methods: ['GET'])]
    public function fetch(
        string $taskId,
        Request $request,
        WorkerAuthService $auth,
        TaskRepository $tasks,
        ToolResolver $toolResolver,
    ): JsonResponse {
        $worker = $auth->findWorker($request->headers->get('Authorization'));
        if (!$worker instanceof Worker) {
            return $this->json(['error' => 'Invalid worker API key'], Response::HTTP_UNAUTHORIZED);
        }

        $task = $tasks->find($taskId);
        if (null === $task || $task->isDeleted()) {
            return $this->json(['error' => 'Task not found'], Response::HTTP_NOT_FOUND);
        }

        $steps = [];
        foreach ($task->getSteps() as $step) {
            $steps[] = [
                'id' => $step->getId()->toRfc4122(),
                'name' => $step->getName(),
                'tags' => $step->getTags(),
                'is_final' => $step->isFinal(),
                'status' => $step->getStatus(),
                'description' => $step->getDescription(),
                // External tool schemas for this step (tag-matched), only.
                'tools' => $toolResolver->schemasForStep($step),
            ];
        }

        return $this->json([
            'task' => [
                'id' => $task->getId()->toRfc4122(),
                'name' => $task->getName(),
                'description' => $task->getDescription(),
                'priority' => $task->getPriority(),
            ],
            'steps' => $steps,
        ]);
    }
}
