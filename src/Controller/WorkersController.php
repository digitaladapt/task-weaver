<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WorkerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only worker browsing (SPEC.md → Admin UI).
 */
#[Route('/workers')]
final class WorkersController extends AbstractController
{
    #[Route('', name: 'app_workers', methods: ['GET'])]
    public function index(WorkerRepository $workers): Response
    {
        return $this->render('admin/workers.html.twig', [
            'workers' => $workers->findBy([], ['createdAt' => 'DESC']),
        ]);
    }
}
