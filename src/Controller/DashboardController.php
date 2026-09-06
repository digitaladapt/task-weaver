<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventRepository;
use App\Repository\McpServerRepository;
use App\Repository\TaskRepository;
use App\Repository\ToolDefRepository;
use App\Repository\WorkerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only admin overview (SPEC.md → Admin UI).
 */
#[Route('/')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(
        TaskRepository $tasks,
        WorkerRepository $workers,
        McpServerRepository $servers,
        ToolDefRepository $tools,
        EventRepository $events,
    ): Response {
        // Grouped status counts via DQL (ServiceEntityRepository::count has no group-by).
        $statuses = [];
        $rows = $tasks->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS n')
            ->groupBy('t.status')
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $statuses[$row['status']] = (int) $row['n'];
        }

        return $this->render('admin/dashboard.html.twig', [
            'totals' => [
                'tasks' => $tasks->count([]),
                'workers' => $workers->count([]),
                'servers' => $servers->count([]),
                'tools' => $tools->count([]),
                'events' => $events->count([]),
            ],
            'statuses' => $statuses,
            'recent_tasks' => $tasks->findBy([], ['createdAt' => 'DESC'], 8),
            'recent_events' => $events->findBy([], ['timestamp' => 'DESC'], 12),
            'recent_workers' => $workers->findBy([], ['createdAt' => 'DESC'], 5),
            'recent_servers' => $servers->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }
}
