<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only task browsing (SPEC.md → Admin UI).
 */
#[Route('/tasks')]
final class TaskController extends AbstractController
{
    #[Route('', name: 'app_tasks', methods: ['GET'])]
    public function index(TaskRepository $tasks): Response
    {
        return $this->render('admin/tasks/index.html.twig', [
            'tasks' => $tasks->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}', name: 'app_task_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(TaskRepository $tasks, string $id): Response
    {
        $task = $tasks->find(Uuid::fromString($id)->toRfc4122());
        if (!$task instanceof Task) {
            throw $this->createNotFoundException('Task not found');
        }

        return $this->render('admin/tasks/show.html.twig', [
            'task' => $task,
        ]);
    }
}
